<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\OrderItemRecoveryEvent;
use App\Models\Modules\Orders\Domain\Payment;
use App\Models\User;
use App\Modules\Accounting\Services\AccountingAuditService;
use App\Modules\Accounting\Services\AccountingRefundPostingService;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Orders\Services\RecoverySettlement\RecoveryRefundAllocationCalculator;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderRefundExecutionService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly RecoveryRefundAllocationCalculator $refundCalculator,
        private readonly PaymentAllocationService $paymentAllocationService,
        private readonly AccountingRefundPostingService $accountingRefundPostingService,
        private readonly AccountingAuditService $accountingAuditService,
    ) {}

    /**
     * @return array{paymentId: int, amount: float, paidTotal: float, paymentStatus: string, idempotent: bool}
     */
    public function execute(
        User $manager,
        int $orderId,
        int $orderItemId,
        string $method,
        float $amount,
        string $idempotencyKey,
        ?string $notes = null,
    ): array {
        if (! $this->userCanExecuteRefund($manager)) {
            throw ValidationException::withMessages([
                'permission' => ['You are not allowed to execute refunds.'],
            ]);
        }

        $order = $this->findOrderScoped($manager, $orderId);
        $item = $this->findOrderItem($order, $orderItemId);
        $amount = round($amount, 2);
        $idempotencyKey = trim($idempotencyKey);

        if ($method !== 'cash') {
            throw ValidationException::withMessages([
                'method' => ['Only cash refunds are supported in this release.'],
            ]);
        }

        if ((string) $order->source === 'qr') {
            throw ValidationException::withMessages([
                'source' => ['QR / gateway orders require gateway refund flow (Phase 3).'],
            ]);
        }

        $existing = OrderItemRecoveryEvent::query()
            ->where('order_item_id', $item->id)
            ->where('event_code', 'refund_executed')
            ->where('payload->idempotencyKey', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            $payload = is_array($existing->payload) ? $existing->payload : [];
            $paymentId = isset($payload['paymentId']) ? (int) $payload['paymentId'] : 0;

            return [
                'paymentId' => $paymentId,
                'amount' => (float) ($payload['amount'] ?? $amount),
                'paidTotal' => (float) $order->fresh()->paid_total,
                'paymentStatus' => (string) $order->fresh()->payment_status,
                'idempotent' => true,
            ];
        }

        $this->assertPreconditions($order, $item, $amount);

        return DB::transaction(function () use ($manager, $order, $item, $amount, $idempotencyKey, $notes, $method): array {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $payment = Payment::query()->create([
                'order_id' => $lockedOrder->id,
                'method' => $method,
                'amount' => $amount,
                'status' => 'refund',
                'paid_at' => now(),
            ]);

            $this->paymentAllocationService->recomputePaymentStatus($lockedOrder->fresh());
            $lockedOrder = $lockedOrder->fresh();

            $this->postAccounting($lockedOrder, $amount, $manager);

            OrderItemRecoveryEvent::query()->create([
                'outlet_id' => $lockedOrder->outlet_id,
                'order_id' => $lockedOrder->id,
                'order_item_id' => $item->id,
                'event_code' => 'refund_executed',
                'recovery_status' => $item->recovery_status,
                'reason' => $notes,
                'payload' => [
                    'idempotencyKey' => $idempotencyKey,
                    'paymentId' => (int) $payment->id,
                    'amount' => $amount,
                    'method' => $method,
                    'posSessionId' => $lockedOrder->pos_session_id,
                    'notes' => $notes,
                ],
                'actor_user_id' => $manager->id,
                'manager_user_id' => $manager->id,
            ]);

            return [
                'paymentId' => (int) $payment->id,
                'amount' => $amount,
                'paidTotal' => (float) $lockedOrder->paid_total,
                'paymentStatus' => (string) $lockedOrder->payment_status,
                'idempotent' => false,
            ];
        });
    }

    public function userCanExecuteRefund(User $user): bool
    {
        return $user->hasPermission('orders.refund.execute');
    }

    private function assertPreconditions(Order $order, OrderItem $item, float $amount): void
    {
        if (! in_array((string) $order->payment_status, ['paid', 'partial'], true)) {
            throw ValidationException::withMessages([
                'paymentStatus' => ['Order must be paid or partially paid to execute a refund.'],
            ]);
        }

        $hasSettlement = OrderItemRecoveryEvent::query()
            ->where('order_item_id', $item->id)
            ->where('event_code', 'recovery_settlement_recorded')
            ->exists();

        $status = strtolower((string) ($item->recovery_status ?? ''));
        $statusOk = in_array($status, ['recovery_approved', 'refunded'], true);

        if (! $hasSettlement && ! $statusOk) {
            throw ValidationException::withMessages([
                'recoveryStatus' => ['Record settlement audit or approve recovery before executing refund.'],
            ]);
        }

        $alreadyRefunded = OrderItemRecoveryEvent::query()
            ->where('order_item_id', $item->id)
            ->where('event_code', 'refund_executed')
            ->exists();
        if ($alreadyRefunded) {
            throw ValidationException::withMessages([
                'refund' => ['Refund already executed for this line.'],
            ]);
        }

        $suggestion = $this->refundCalculator->suggest($order, $item, $amount);
        $cap = (float) ($suggestion['capped'] ?? 0);
        if ($amount > $cap + 0.00001) {
            throw ValidationException::withMessages([
                'amount' => [sprintf('Refund amount exceeds safe cap (%.2f).', $cap)],
            ]);
        }

        $remainingPaid = (float) Payment::query()
            ->where('order_id', $order->id)
            ->where('status', 'paid')
            ->sum('amount')
            - (float) Payment::query()
                ->where('order_id', $order->id)
                ->where('status', 'refund')
                ->sum('amount');

        if ($amount > max(0, $remainingPaid) + 0.00001) {
            throw ValidationException::withMessages([
                'amount' => ['Refund amount exceeds remaining paid total on this order.'],
            ]);
        }
    }

    private function postAccounting(Order $order, float $amount, User $manager): void
    {
        $outletId = $order->outlet_id !== null ? (int) $order->outlet_id : null;
        $remainingPaid = (float) $order->paid_total;

        if ($remainingPaid <= 0.00001 || abs($amount - $remainingPaid) <= 0.00001) {
            $this->accountingRefundPostingService->postRefundForOrder((int) $order->id, $amount, $outletId, $manager);
        } else {
            $this->accountingAuditService->log(
                'refund_partial_logged',
                'order_payment',
                (int) $order->id,
                $outletId,
                $manager,
                ['amount' => $amount, 'remainingPaid' => $remainingPaid],
            );
        }
    }

    private function findOrderScoped(User $user, int $orderId): Order
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $order = $this->orderRepository->findScoped($orderId, $allowed);
        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        return $order;
    }

    private function findOrderItem(Order $order, int $orderItemId): OrderItem
    {
        $item = OrderItem::query()
            ->where('order_id', $order->id)
            ->whereKey($orderItemId)
            ->first();
        if ($item === null) {
            throw (new ModelNotFoundException)->setModel(OrderItem::class, [(string) $orderItemId]);
        }

        return $item;
    }
}
