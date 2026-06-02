<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\User;
use App\Modules\Orders\DTOs\CreateOrderData;
use App\Modules\Orders\Events\QrOrderDecisionChanged;
use App\Modules\Orders\Repositories\QrOrderRequestRepositoryInterface;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QrOrderApprovalService
{
    public function __construct(
        private readonly QrOrderRequestRepositoryInterface $qrOrderRequestRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly OrderService $orderService,
        private readonly QrOrderExpiryService $qrOrderExpiryService,
        private readonly PosTransitionValidator $transitionValidator,
        private readonly PosIdempotencyService $idempotencyService,
        private readonly PosAuditLogService $auditLogService,
    ) {}

    public function confirm(
        User $user,
        int $requestId,
        string $mode = 'confirm_only',
        array $payments = [],
        ?string $idempotencyKey = null,
    ): QrOrderRequest {
        if (! in_array($mode, ['confirm_only', 'pay_and_confirm'], true)) {
            throw ValidationException::withMessages([
                'mode' => ['Mode must be confirm_only or pay_and_confirm.'],
            ]);
        }

        return DB::transaction(function () use ($user, $requestId, $mode, $payments, $idempotencyKey): QrOrderRequest {
            return $this->idempotencyService->run(
                'qr-orders.confirm.'.$requestId.'.'.$mode,
                $idempotencyKey,
                ['requestId' => $requestId, 'mode' => $mode, 'payments' => $payments],
                function () use ($user, $requestId, $mode, $payments): QrOrderRequest {
                    $request = $this->resolveScoped($user, $requestId, true);
                    $request = $this->qrOrderExpiryService->markExpiredIfNeeded($request);
                    $fromStatus = (string) $request->status;

                    if ((string) $request->status === 'expired') {
                        throw ValidationException::withMessages([
                            'request' => ['Expired request cannot be confirmed.'],
                        ]);
                    }
                    if ((string) $request->status === 'rejected') {
                        throw ValidationException::withMessages([
                            'request' => ['Rejected request cannot be confirmed.'],
                        ]);
                    }
                    if ((string) $request->status !== 'pending_cashier_confirmation') {
                        throw ValidationException::withMessages([
                            'request' => ['Only pending cashier confirmations can be confirmed.'],
                        ]);
                    }

                    $posSession = PosSession::query()
                        ->where('outlet_id', (int) $request->outlet_id)
                        ->where('status', 'open')
                        ->latest('id')
                        ->first();
                    if ($posSession === null) {
                        throw ValidationException::withMessages([
                            'outletId' => ['No open POS session for outlet.'],
                        ]);
                    }

                    $requestItems = $request->relationLoaded('items') ? $request->items : $request->items()->get();
                    $menuItems = MenuItem::query()
                        ->whereIn('id', $requestItems->pluck('menu_item_id')->all())
                        ->get()
                        ->keyBy('id');

                    $orderItems = [];
                    foreach ($requestItems as $requestItem) {
                        $menuItem = $menuItems->get((int) $requestItem->menu_item_id);
                        if ($menuItem === null) {
                            throw ValidationException::withMessages([
                                'items' => ['Menu item not found for one or more request items.'],
                            ]);
                        }
                        if ($menuItem->outlet_id !== null && (int) $menuItem->outlet_id !== (int) $request->outlet_id) {
                            throw ValidationException::withMessages([
                                'items' => ['Menu item does not belong to request outlet.'],
                            ]);
                        }

                        $orderItems[] = [
                            'id' => (string) $menuItem->id,
                            'name' => (string) $menuItem->name,
                            'qty' => (float) $requestItem->qty,
                            'price' => (float) $menuItem->price,
                            'notes' => $requestItem->notes,
                        ];
                    }

                    $subtotal = collect($orderItems)->sum(fn (array $item): float => (float) $item['qty'] * (float) $item['price']);
                    $orderPayments = [];
                    if ($mode === 'pay_and_confirm') {
                        $orderPayments = $payments !== [] ? $payments : [['method' => 'cash', 'amount' => $subtotal]];
                        $paidSum = collect($orderPayments)->sum(fn (array $payment): float => (float) ($payment['amount'] ?? 0));
                        if (abs($paidSum - $subtotal) > 0.01) {
                            throw ValidationException::withMessages([
                                'payments' => ['Payments must equal order total for pay and confirm.'],
                            ]);
                        }
                    }

                    $order = $this->orderService->create(
                        new CreateOrderData(
                            tenantId: null,
                            outletId: (int) $request->outlet_id,
                            code: $this->generateOrderCode(),
                            source: 'qr',
                            orderType: 'Dine In',
                            status: 'confirmed',
                            paymentStatus: 'unpaid',
                            items: $orderItems,
                            payments: $orderPayments,
                            subtotal: $subtotal,
                            tax: 0,
                            total: $subtotal,
                            discountAmount: 0,
                            customerName: $request->customer_name,
                            customerPhone: null,
                            tableId: (int) $request->table_id,
                            tableNumber: null,
                            createdAt: null,
                            confirmedAt: now()->toISOString(),
                            splitBill: null,
                            serviceMode: 'dine_in',
                            orderChannel: 'qr',
                            posSessionId: (int) $posSession->id,
                        ),
                        $user,
                    );

                    $this->transitionValidator->assertQrRequestStatusTransition($fromStatus, 'confirmed');
                    $this->qrOrderRequestRepository->update($request, [
                        'status' => 'confirmed',
                        'decision_mode' => $mode,
                        'confirmed_at' => now(),
                        'confirmed_by_user_id' => (int) $user->id,
                        'order_id' => (int) $order->id,
                    ]);

                    $resolved = $this->resolveScoped($user, $requestId, false);
                    $this->auditLogService->log(
                        'qr.request.confirmed',
                        'qr_order_request',
                        (int) $resolved->id,
                        (int) $resolved->outlet_id,
                        $user,
                        ['orderId' => (int) ($resolved->order_id ?? 0), 'mode' => $mode]
                    );
                    event(new QrOrderDecisionChanged(
                        outletId: (int) $resolved->outlet_id,
                        requestId: (int) $resolved->id,
                        status: (string) $resolved->status,
                        orderId: $resolved->order_id !== null ? (int) $resolved->order_id : null,
                        sequence: (int) $resolved->id,
                        aggregateUpdatedAtIso: $resolved->updated_at?->toIso8601String()
                    ));

                    return $resolved;
                }
            );
        });
    }

    public function reject(User $user, int $requestId, ?string $reason = null, ?string $idempotencyKey = null): QrOrderRequest
    {
        return DB::transaction(function () use ($user, $requestId, $reason, $idempotencyKey): QrOrderRequest {
            return $this->idempotencyService->run(
                'qr-orders.reject.'.$requestId,
                $idempotencyKey,
                ['requestId' => $requestId, 'reason' => $reason],
                function () use ($user, $requestId, $reason): QrOrderRequest {
                    $request = $this->resolveScoped($user, $requestId, true);
                    $request = $this->qrOrderExpiryService->markExpiredIfNeeded($request);
                    $fromStatus = (string) $request->status;

                    if ((string) $request->status === 'confirmed') {
                        throw ValidationException::withMessages([
                            'request' => ['Confirmed request cannot be rejected.'],
                        ]);
                    }
                    if ((string) $request->status === 'expired') {
                        throw ValidationException::withMessages([
                            'request' => ['Expired request cannot be rejected.'],
                        ]);
                    }
                    if ((string) $request->status === 'rejected') {
                        return $request;
                    }

                    $this->transitionValidator->assertQrRequestStatusTransition($fromStatus, 'rejected');
                    $this->qrOrderRequestRepository->update($request, [
                        'status' => 'rejected',
                        'rejected_at' => now(),
                        'rejected_by_user_id' => (int) $user->id,
                        'rejection_reason' => $reason,
                    ]);

                    $resolved = $this->resolveScoped($user, $requestId, false);
                    $this->auditLogService->log(
                        'qr.request.rejected',
                        'qr_order_request',
                        (int) $resolved->id,
                        (int) $resolved->outlet_id,
                        $user,
                        ['reason' => $reason]
                    );
                    event(new QrOrderDecisionChanged(
                        outletId: (int) $resolved->outlet_id,
                        requestId: (int) $resolved->id,
                        status: (string) $resolved->status,
                        reason: $reason,
                        sequence: (int) $resolved->id,
                        aggregateUpdatedAtIso: $resolved->updated_at?->toIso8601String()
                    ));

                    return $resolved;
                }
            );
        });
    }

    private function resolveScoped(User $user, int $requestId, bool $lock): QrOrderRequest
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);

        $query = QrOrderRequest::query()
            ->whereIn('outlet_id', $allowed === [] ? [-1] : $allowed)
            ->whereKey($requestId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $request = $query->with(['items.menuItem', 'table', 'order'])->first();
        if ($request === null) {
            throw (new ModelNotFoundException)->setModel(QrOrderRequest::class, [(string) $requestId]);
        }

        return $request;
    }

    private function generateOrderCode(): string
    {
        return 'QR-ORD-'.now()->format('YmdHis').'-'.strtoupper((string) str()->random(4));
    }
}
