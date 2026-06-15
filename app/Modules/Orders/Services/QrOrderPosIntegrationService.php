<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\User;
use App\Modules\Orders\Events\QrOrderDecisionChanged;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Orders\Repositories\QrOrderRequestRepositoryInterface;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QrOrderPosIntegrationService
{
    public function __construct(
        private readonly QrOrderRequestRepositoryInterface $qrOrderRequestRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly QrOrderExpiryService $qrOrderExpiryService,
        private readonly PosTransitionValidator $transitionValidator,
        private readonly PosAuditLogService $auditLogService,
        private readonly OrderSourceLinkService $orderSourceLinkService,
    ) {}

    public function preview(User $user, int $requestId): QrOrderRequest
    {
        $request = $this->resolveScoped($user, $requestId);
        $request = $this->qrOrderExpiryService->markExpiredIfNeeded($request);

        $status = (string) $request->status;
        if (! in_array($status, ['pending_cashier_confirmation', 'under_review'], true)) {
            throw ValidationException::withMessages([
                'request' => ['Only submitted or in-review QR orders can be previewed.'],
            ]);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    public function openInPos(User $user, int $requestId): array
    {
        return DB::transaction(function () use ($user, $requestId): array {
            $request = $this->resolveScoped($user, $requestId, true);
            $request = $this->qrOrderExpiryService->markExpiredIfNeeded($request);
            $fromStatus = (string) $request->status;

            if (! in_array($fromStatus, ['pending_cashier_confirmation', 'under_review', 'confirmed'], true)) {
                throw ValidationException::withMessages([
                    'request' => ['Only pending or in-review QR orders can be opened in POS.'],
                ]);
            }

            $linkedOrderId = $request->order_id !== null ? (int) $request->order_id : null;
            if ($linkedOrderId !== null) {
                $request = $request->fresh(['items.menuItem', 'table', 'order.items']);
                $linkedOrder = $request->order;
                if ($linkedOrder === null) {
                    throw ValidationException::withMessages([
                        'request' => ['Linked POS order was not found.'],
                    ]);
                }

                $this->auditLogService->log(
                    'qr_order.opened_in_pos',
                    'qr_order_request',
                    (int) $request->id,
                    (int) $request->outlet_id,
                    $user,
                    $this->linkAuditPayload($request, $linkedOrder)
                );

                return [
                    'request' => $request,
                    'posSession' => [
                        'sessionType' => 'qr_order',
                        'sourceOrderCode' => (string) $request->request_code,
                    ],
                    'loadPayload' => $this->buildPosLoadPayloadFromOrder($request, $linkedOrder),
                    'linkedOrder' => $this->orderSourceLinkService->buildLinkedOrder($request),
                ];
            }

            $snapshot = $this->buildOriginalItemsSnapshot($request);
            $updates = [];

            if ($request->original_items_snapshot === null) {
                $updates['original_items_snapshot'] = $snapshot;
            }

            if ($fromStatus === 'pending_cashier_confirmation') {
                $this->transitionValidator->assertQrRequestStatusTransition($fromStatus, 'under_review');
                $updates['status'] = 'under_review';
            }

            $updates['opened_in_pos_at'] = now();
            $updates['opened_in_pos_by_user_id'] = (int) $user->id;

            if ($updates !== []) {
                $this->qrOrderRequestRepository->update($request, $updates);
            }

            $request = $request->fresh(['items.menuItem', 'table']);

            $this->auditLogService->log(
                'qr_order.opened_in_pos',
                'qr_order_request',
                (int) $request->id,
                (int) $request->outlet_id,
                $user,
                ['requestCode' => (string) $request->request_code]
            );

            return [
                'request' => $request,
                'posSession' => [
                    'sessionType' => 'qr_order',
                    'sourceOrderCode' => (string) $request->request_code,
                ],
                'loadPayload' => $this->buildPosLoadPayload($request),
                'linkedOrder' => null,
            ];
        });
    }

    public function attachOrderFromPos(?User $user, int $qrOrderRequestId, Order $order): void
    {
        $this->ensureLinkedFromPos($user, $qrOrderRequestId, $order);
    }

    public function ensureLinkedFromPos(?User $user, int $qrOrderRequestId, Order $order): void
    {
        DB::transaction(function () use ($user, $qrOrderRequestId, $order): void {
            $request = QrOrderRequest::query()->whereKey($qrOrderRequestId)->lockForUpdate()->first();
            if ($request === null) {
                throw (new ModelNotFoundException())->setModel(QrOrderRequest::class, [(string) $qrOrderRequestId]);
            }

            if ((int) ($request->order_id ?? 0) === (int) $order->id) {
                $this->ensureOrderSourceLink($order, $request);
                $this->syncLinkedRequestWithOrder($user, $request, $order);

                return;
            }

            if ((int) ($request->order_id ?? 0) > 0 && (int) $request->order_id !== (int) $order->id) {
                throw ValidationException::withMessages([
                    'qrOrderRequestId' => ['This QR order is already linked to another POS order.'],
                ]);
            }

            if (! in_array((string) $request->status, ['under_review', 'pending_cashier_confirmation'], true)) {
                throw ValidationException::withMessages([
                    'qrOrderRequestId' => ['QR order is not eligible for POS confirmation.'],
                ]);
            }

            $fromStatus = (string) $request->status;
            $nextStatus = (string) $order->payment_status === 'paid' ? 'paid' : 'confirmed';
            $this->transitionValidator->assertQrRequestStatusTransition($fromStatus, $nextStatus);

            $this->qrOrderRequestRepository->update($request, [
                'status' => $nextStatus,
                'order_id' => (int) $order->id,
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $user !== null ? (int) $user->id : null,
                'decision_mode' => 'confirm_only',
                'original_items_snapshot' => $request->original_items_snapshot ?? $this->buildOriginalItemsSnapshot($request),
                'review_draft' => null,
                'customer_approval_status' => $this->resolveCustomerApprovalAfterLink($request),
            ]);

            $this->ensureOrderSourceLink($order, $request);

            $resolved = $request->fresh();
            $linkPayload = $this->linkAuditPayload($resolved, $order);

            $this->auditLogService->log(
                'qr_order.linked_to_pos_order',
                'qr_order_request',
                (int) $resolved->id,
                (int) $resolved->outlet_id,
                $user,
                $linkPayload
            );
            $this->auditLogService->log(
                'order.created_from_qr_order',
                'order',
                (int) $order->id,
                (int) ($order->outlet_id ?? 0),
                $user,
                $linkPayload
            );
            $this->auditLogService->log(
                'qr_order.confirmed',
                'qr_order_request',
                (int) $resolved->id,
                (int) $resolved->outlet_id,
                $user,
                ['orderId' => (int) $order->id]
            );

            if ($nextStatus === 'paid') {
                $this->auditLogService->log(
                    'qr_order.paid',
                    'qr_order_request',
                    (int) $resolved->id,
                    (int) $resolved->outlet_id,
                    $user,
                    ['orderId' => (int) $order->id]
                );
            }

            event(new QrOrderDecisionChanged(
                outletId: (int) $resolved->outlet_id,
                requestId: (int) $resolved->id,
                status: (string) $resolved->status,
                orderId: (int) $order->id,
                sequence: (int) $resolved->id,
                aggregateUpdatedAtIso: $resolved->updated_at?->toIso8601String()
            ));
        });
    }

    private function syncLinkedRequestWithOrder(?User $user, QrOrderRequest $request, Order $order): void
    {
        $updates = [];
        if (is_array($request->review_draft) && $request->review_draft !== []) {
            $updates['review_draft'] = null;
        }

        $requestStatus = (string) $request->status;
        if ($requestStatus === 'confirmed' && (string) $order->payment_status === 'paid') {
            $this->transitionValidator->assertQrRequestStatusTransition('confirmed', 'paid');
            $updates['status'] = 'paid';
        }

        if ($updates !== []) {
            $this->qrOrderRequestRepository->update($request, $updates);
            $resolved = $request->fresh();
            if (($updates['status'] ?? null) === 'paid') {
                $this->auditLogService->log(
                    'qr_order.paid',
                    'qr_order_request',
                    (int) $resolved->id,
                    (int) $resolved->outlet_id,
                    $user,
                    ['orderId' => (int) $order->id]
                );
                event(new QrOrderDecisionChanged(
                    outletId: (int) $resolved->outlet_id,
                    requestId: (int) $resolved->id,
                    status: (string) $resolved->status,
                    orderId: (int) $order->id,
                    sequence: (int) $resolved->id,
                    aggregateUpdatedAtIso: $resolved->updated_at?->toIso8601String()
                ));
            }
        }
    }

    private function resolveCustomerApprovalAfterLink(QrOrderRequest $request): ?string
    {
        if ((string) ($request->customer_approval_status ?? '') === 'pending_approval') {
            return 'approved';
        }

        return $request->customer_approval_status;
    }

    public function syncPaidStatusFromOrder(?User $user, Order $order): void
    {
        if ((string) $order->payment_status !== 'paid') {
            return;
        }

        $request = QrOrderRequest::query()
            ->where('order_id', (int) $order->id)
            ->first();

        if ($request === null || (string) $request->status === 'paid') {
            return;
        }

        if ((string) $request->status !== 'confirmed') {
            return;
        }

        $this->transitionValidator->assertQrRequestStatusTransition('confirmed', 'paid');
        $this->qrOrderRequestRepository->update($request, ['status' => 'paid']);

        $resolved = $request->fresh();
        $this->auditLogService->log(
            'qr_order.paid',
            'qr_order_request',
            (int) $resolved->id,
            (int) $resolved->outlet_id,
            $user,
            ['orderId' => (int) $order->id]
        );

        event(new QrOrderDecisionChanged(
            outletId: (int) $resolved->outlet_id,
            requestId: (int) $resolved->id,
            status: (string) $resolved->status,
            orderId: (int) $order->id,
            sequence: (int) $resolved->id,
            aggregateUpdatedAtIso: $resolved->updated_at?->toIso8601String()
        ));
    }

    /** @return list<array<string, mixed>> */
    public function buildPosAdjustments(QrOrderRequest $request, Order $order): array
    {
        $snapshot = is_array($request->original_items_snapshot) ? $request->original_items_snapshot : null;
        if ($snapshot === null || $snapshot === []) {
            return [];
        }

        $originalByMenuId = collect($snapshot)->keyBy(fn (array $row): int => (int) ($row['menuItemId'] ?? 0));
        $orderByMenuId = $order->items->groupBy(fn ($item): int => (int) ($item->item_id ?? 0));

        $adjustments = [];

        foreach ($originalByMenuId as $menuItemId => $original) {
            $originalQty = (float) ($original['qty'] ?? 0);
            $orderLines = $orderByMenuId->get((int) $menuItemId);
            $orderQty = $orderLines !== null
                ? (float) $orderLines->sum(fn ($line): float => (float) $line->qty)
                : 0.0;

            if ($orderQty <= 0 && $originalQty > 0) {
                $adjustments[] = [
                    'type' => 'removed',
                    'name' => (string) ($original['name'] ?? 'Item'),
                    'message' => 'Removed by cashier',
                ];
            } elseif ($orderQty !== $originalQty && $orderQty > 0) {
                $adjustments[] = [
                    'type' => 'modified',
                    'name' => (string) ($original['name'] ?? 'Item'),
                    'from' => (string) $originalQty,
                    'to' => (string) $orderQty,
                    'message' => 'Quantity updated by cashier',
                ];
            }
        }

        foreach ($orderByMenuId as $menuItemId => $lines) {
            if ($originalByMenuId->has((int) $menuItemId)) {
                continue;
            }
            $line = $lines->first();
            $adjustments[] = [
                'type' => 'added',
                'name' => (string) ($line->name ?? 'Item'),
                'message' => 'Added by cashier',
            ];
        }

        return $adjustments;
    }

    /** @return array<string, mixed> */
    private function buildPosLoadPayload(QrOrderRequest $request): array
    {
        $request->loadMissing(['items.menuItem', 'table']);
        $items = [];
        $subtotal = 0.0;

        foreach ($request->items as $item) {
            $price = (float) ($item->menuItem?->price ?? 0);
            $qty = (float) $item->qty;
            $lineTotal = round($qty * $price, 2);
            $subtotal += $lineTotal;
            $items[] = [
                'id' => (string) $item->menu_item_id,
                'menuItemId' => (int) $item->menu_item_id,
                'name' => (string) ($item->menuItem?->name ?? 'Item'),
                'price' => $price,
                'qty' => $qty,
                'emoji' => $item->menuItem?->emoji ?? null,
                'notes' => $item->notes,
                'lineTotal' => $lineTotal,
            ];
        }

        return [
            'requestId' => (string) $request->id,
            'requestCode' => (string) $request->request_code,
            'outletId' => (int) $request->outlet_id,
            'tableId' => (int) $request->table_id,
            'tableName' => $request->table?->name,
            'customerName' => $request->customer_name,
            'linkedOrderId' => null,
            'linkedOrderCode' => null,
            'items' => $items,
            'subtotal' => round($subtotal, 2),
            'tax' => 0.0,
            'total' => round($subtotal, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function buildPosLoadPayloadFromOrder(QrOrderRequest $request, Order $order): array
    {
        $order->loadMissing('items');
        $items = [];
        $subtotal = (float) $order->subtotal;
        $tax = (float) $order->tax;
        $total = (float) $order->total;

        foreach ($order->items as $item) {
            $items[] = [
                'id' => (string) ($item->item_id ?? $item->id),
                'menuItemId' => (int) ($item->item_id ?? 0),
                'name' => (string) $item->name,
                'price' => (float) $item->price,
                'qty' => (float) $item->qty,
                'emoji' => $item->emoji,
                'notes' => $item->notes,
                'lineTotal' => (float) $item->line_total,
            ];
        }

        return [
            'requestId' => (string) $request->id,
            'requestCode' => (string) $request->request_code,
            'outletId' => (int) $request->outlet_id,
            'tableId' => (int) ($order->table_id ?? $request->table_id),
            'tableName' => $order->table_name ?? $request->table?->name,
            'customerName' => $order->customer_name ?? $request->customer_name,
            'linkedOrderId' => (string) $order->id,
            'linkedOrderCode' => (string) $order->code,
            'items' => $items,
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'total' => round($total, 2),
        ];
    }

    private function ensureOrderSourceLink(Order $order, QrOrderRequest $request): void
    {
        if (
            (string) ($order->source_type ?? '') === 'qr_order'
            && (int) ($order->source_id ?? 0) === (int) $request->id
        ) {
            return;
        }

        $this->orderRepository->update($order, [
            'source_type' => 'qr_order',
            'source_id' => (int) $request->id,
            'source_code' => (string) $request->request_code,
        ]);
    }

    /** @return array{qrOrderId: int, qrOrderCode: string, orderId: int, orderNo: string} */
    private function linkAuditPayload(QrOrderRequest $request, Order $order): array
    {
        return [
            'qrOrderId' => (int) $request->id,
            'qrOrderCode' => (string) $request->request_code,
            'orderId' => (int) $order->id,
            'orderNo' => (string) $order->code,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildOriginalItemsSnapshot(QrOrderRequest $request): array
    {
        $request->loadMissing(['items.menuItem']);
        $menuItems = MenuItem::query()
            ->whereIn('id', $request->items->pluck('menu_item_id')->all())
            ->get()
            ->keyBy('id');

        return $request->items
            ->map(function ($item) use ($menuItems): array {
                $menuItem = $menuItems->get((int) $item->menu_item_id);

                return [
                    'menuItemId' => (int) $item->menu_item_id,
                    'name' => (string) ($menuItem?->name ?? 'Item'),
                    'qty' => (float) $item->qty,
                    'unitPrice' => (float) ($menuItem?->price ?? 0),
                    'notes' => $item->notes,
                ];
            })
            ->values()
            ->all();
    }

    private function resolveScoped(User $user, int $requestId, bool $lock = false): QrOrderRequest
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $query = QrOrderRequest::query()
            ->whereIn('outlet_id', $allowed === [] ? [-1] : $allowed)
            ->whereKey($requestId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $request = $query->with(['items.menuItem', 'table', 'order.items'])->first();
        if ($request === null) {
            throw (new ModelNotFoundException())->setModel(QrOrderRequest::class, [(string) $requestId]);
        }

        return $request;
    }
}
