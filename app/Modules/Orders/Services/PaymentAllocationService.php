<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\OrderPaymentAllocation;
use App\Models\Modules\Orders\Domain\OrderSplit;
use App\Models\Modules\Orders\Domain\OrderSplitItem;
use App\Models\Modules\Orders\Domain\Payment;
use App\Models\User;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentAllocationService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PosIdempotencyService $idempotencyService,
        private readonly PosTransitionValidator $transitionValidator,
        private readonly PosAuditLogService $auditLogService,
        private readonly OptimisticConcurrencyService $optimisticConcurrencyService,
    ) {}

    /**
     * @param array<int,array<string,mixed>> $payments
     */
    public function addPayments(User $user, int $orderId, array $payments, ?string $idempotencyKey = null, ?string $expectedUpdatedAt = null): ?Order
    {
        return DB::transaction(function () use ($user, $orderId, $payments, $idempotencyKey, $expectedUpdatedAt): ?Order {
            return $this->idempotencyService->run(
                'orders.payments.add.'.$orderId,
                $idempotencyKey,
                ['payments' => $payments, 'expectedUpdatedAt' => $expectedUpdatedAt],
                function () use ($user, $orderId, $payments, $expectedUpdatedAt): ?Order {
                    $order = $this->findScopedOrderForUpdate($user, $orderId);
                    if ($order === null) {
                        return null;
                    }

                    $this->optimisticConcurrencyService->assertNotStale($order, $expectedUpdatedAt);
                    $this->assertOrderEditable($order);
                    $normalized = $this->normalizePayments($payments);
                    $this->assertNoOverpayment($order, $normalized);
                    $beforePaymentStatus = (string) $order->payment_status;
                    $this->storePayments($order, $normalized);
                    $this->recomputePaymentStatus($order);
                    $fresh = $this->orderRepository->findWithRelations($order->id);
                    if ($fresh !== null) {
                        $this->transitionValidator->assertPaymentStatusTransition($beforePaymentStatus, (string) $fresh->payment_status);
                        $this->auditLogService->log(
                            'payment.recorded',
                            'order',
                            (int) $fresh->id,
                            (int) $fresh->outlet_id,
                            $user,
                            ['paymentCount' => count($payments), 'paymentStatus' => (string) $fresh->payment_status]
                        );
                    }

                    return $fresh;
                }
            );
        });
    }

    public function listPayments(User $user, int $orderId): Collection
    {
        $order = $this->findScopedOrder($user, $orderId);
        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        return Payment::query()
            ->where('order_id', $order->id)
            ->with(['allocations', 'split'])
            ->orderBy('id')
            ->get();
    }

    public function recomputePaymentStatus(Order $order): void
    {
        $paidTotal = (float) Payment::query()->where('order_id', $order->id)->sum('amount');
        $paymentStatus = $paidTotal >= (float) $order->total ? 'paid' : ($paidTotal > 0 ? 'partial' : 'unpaid');
        $status = $paymentStatus === 'paid' && (string) $order->status !== 'cancelled'
            ? 'completed'
            : (string) $order->status;

        $this->orderRepository->update($order, [
            'status' => $status,
            'payment_status' => $paymentStatus,
            'paid_total' => $paidTotal,
            'balance_due' => max(0, (float) $order->total - $paidTotal),
        ]);
    }

    private function findScopedOrder(User $user, int $orderId): ?Order
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);

        return $this->orderRepository->findScoped($orderId, $allowed);
    }

    private function findScopedOrderForUpdate(User $user, int $orderId): ?Order
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);

        return Order::query()
            ->whereIn('outlet_id', $allowed)
            ->whereKey($orderId)
            ->lockForUpdate()
            ->first();
    }

    private function assertOrderEditable(Order $order): void
    {
        if (! in_array((string) $order->payment_status, ['unpaid', 'partial'], true)) {
            throw ValidationException::withMessages([
                'paymentStatus' => ['Order is no longer editable.'],
            ]);
        }
        if ((string) $order->status === 'cancelled') {
            throw ValidationException::withMessages([
                'status' => ['Cancelled orders cannot receive payment.'],
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $payments
     */
    private function normalizePayments(array $payments): array
    {
        return array_map(function (array $payment): array {
            return [
                'method' => $this->normalizePaymentMethod((string) ($payment['method'] ?? '')),
                'amount' => (float) ($payment['amount'] ?? 0),
                'status' => (string) ($payment['status'] ?? 'paid'),
                'paidAt' => $payment['paidAt'] ?? null,
                'splitBillLabel' => $payment['splitBillLabel'] ?? null,
                'splitBillGroup' => $payment['splitBillGroup'] ?? null,
                'orderSplitId' => isset($payment['orderSplitId']) ? (int) $payment['orderSplitId'] : null,
                'allocations' => collect($payment['allocations'] ?? [])->map(fn (array $allocation): array => [
                    'orderItemId' => (int) ($allocation['orderItemId'] ?? 0),
                    'qty' => (float) ($allocation['qty'] ?? 0),
                    'amount' => (float) ($allocation['amount'] ?? 0),
                ])->values()->all(),
            ];
        }, $payments);
    }

    /**
     * Normalize to `payments.method` enum values (see migration).
     * Kept in sync with {@see OrderService::normalizePaymentMethod()} for the unauthenticated POS bridge path.
     */
    private function normalizePaymentMethod(string $method): string
    {
        return match (strtolower(trim($method))) {
            'cash' => 'cash',
            'qris', 'qr', 'qr code' => 'qris',
            'e-wallet', 'ewallet' => 'ewallet',
            'card', 'credit card', 'debit card' => 'card',
            'transfer', 'bank transfer' => 'transfer',
            default => 'transfer',
        };
    }

    /**
     * @param array<int,array<string,mixed>> $payments
     */
    private function assertNoOverpayment(Order $order, array $payments): void
    {
        $existingPaid = (float) Payment::query()->where('order_id', $order->id)->sum('amount');
        $incoming = collect($payments)->sum(fn (array $p): float => (float) $p['amount']);
        if (($existingPaid + $incoming) > ((float) $order->total + 0.00001)) {
            throw ValidationException::withMessages([
                'payments' => ['Total allocated payment cannot exceed order total.'],
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $payments
     */
    private function storePayments(Order $order, array $payments): void
    {
        $orderItems = OrderItem::query()
            ->where('order_id', $order->id)
            ->get(['id', 'qty', 'line_total'])
            ->keyBy('id');
        $existingAllocatedQtyByItem = OrderPaymentAllocation::query()
            ->selectRaw('order_item_id, SUM(qty) as qty')
            ->whereHas('payment', fn ($query) => $query->where('order_id', $order->id))
            ->groupBy('order_item_id')
            ->pluck('qty', 'order_item_id')
            ->map(fn ($qty): float => (float) $qty);
        $runningAllocatedQtyByItem = [];

        foreach ($payments as $payment) {
            $allocations = $payment['allocations'] ?? [];
            $split = $this->resolveSplitForPayment($order, $payment['orderSplitId'] ?? null);
            $this->validatePaymentAllocations(
                $orderItems,
                $existingAllocatedQtyByItem,
                $runningAllocatedQtyByItem,
                $payment,
                $allocations,
                $split
            );

            $storedPayment = Payment::query()->create([
                'order_id' => $order->id,
                'order_split_id' => $split?->id,
                'method' => $payment['method'],
                'amount' => $payment['amount'],
                'status' => $payment['status'],
                'split_bill_label' => $payment['splitBillLabel'],
                'split_bill_group' => $payment['splitBillGroup'],
                'paid_at' => $payment['paidAt'] ?? now(),
            ]);

            foreach ($allocations as $allocation) {
                OrderPaymentAllocation::query()->create([
                    'payment_id' => $storedPayment->id,
                    'order_item_id' => $allocation['orderItemId'],
                    'qty' => $allocation['qty'],
                    'amount' => $allocation['amount'],
                ]);
            }
        }
    }

    private function resolveSplitForPayment(Order $order, ?int $splitId): ?OrderSplit
    {
        if ($splitId === null || $splitId < 1) {
            return null;
        }

        $split = OrderSplit::query()
            ->where('order_id', $order->id)
            ->whereKey($splitId)
            ->first();
        if ($split === null) {
            throw ValidationException::withMessages([
                'payments' => ['Referenced split does not belong to this order.'],
            ]);
        }

        return $split;
    }

    /**
     * @param array<string,mixed> $payment
     * @param array<int,array{orderItemId:int,qty:float,amount:float}> $allocations
     * @param array<int,float> $runningAllocatedQtyByItem
     */
    private function validatePaymentAllocations(
        Collection $orderItems,
        Collection $existingAllocatedQtyByItem,
        array &$runningAllocatedQtyByItem,
        array $payment,
        array $allocations,
        ?OrderSplit $split
    ): void {
        $paymentAmount = (float) $payment['amount'];
        if ($paymentAmount <= 0) {
            throw ValidationException::withMessages([
                'payments' => ['Payment amount must be greater than zero.'],
            ]);
        }

        $allocationAmount = collect($allocations)->sum(fn (array $allocation): float => (float) $allocation['amount']);
        if ($allocations !== [] && abs($allocationAmount - $paymentAmount) > 0.01) {
            throw ValidationException::withMessages([
                'payments' => ['Allocation amount must match payment amount for each payment.'],
            ]);
        }

        $allocIds = collect($allocations)->pluck('orderItemId');
        if ($allocIds->count() !== $allocIds->unique()->count()) {
            throw ValidationException::withMessages([
                'payments' => ['Duplicate allocation item in a payment is not allowed.'],
            ]);
        }

        foreach ($allocations as $allocation) {
            $orderItemId = $allocation['orderItemId'];
            $orderItem = $orderItems->get($orderItemId);
            if ($orderItem === null) {
                throw ValidationException::withMessages([
                    'payments' => ['Allocation order item is not part of this order.'],
                ]);
            }

            $allocatedQty = (float) $allocation['qty'];
            $allocatedAmount = (float) $allocation['amount'];
            if ($allocatedQty <= 0 || $allocatedAmount <= 0) {
                throw ValidationException::withMessages([
                    'payments' => ['Allocation qty/amount must be greater than zero.'],
                ]);
            }

            $prevQty = (float) ($existingAllocatedQtyByItem->get($orderItemId, 0))
                + (float) ($runningAllocatedQtyByItem[$orderItemId] ?? 0);
            if (($prevQty + $allocatedQty) > ((float) $orderItem->qty + 0.00001)) {
                throw ValidationException::withMessages([
                    'payments' => ['Allocation qty exceeds order item qty.'],
                ]);
            }

            if ($split !== null) {
                $splitItem = OrderSplitItem::query()
                    ->where('order_split_id', $split->id)
                    ->where('order_item_id', $orderItemId)
                    ->first();
                if ($splitItem === null) {
                    throw ValidationException::withMessages([
                        'payments' => ['Payment allocation must match split item rows.'],
                    ]);
                }

                if ($allocatedQty > ((float) $splitItem->qty + 0.00001) || $allocatedAmount > ((float) $splitItem->amount + 0.00001)) {
                    throw ValidationException::withMessages([
                        'payments' => ['Split allocations cannot exceed split qty/amount.'],
                    ]);
                }
            }

            $runningAllocatedQtyByItem[$orderItemId] = ($runningAllocatedQtyByItem[$orderItemId] ?? 0) + $allocatedQty;
        }
    }
}
