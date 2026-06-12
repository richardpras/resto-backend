<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\User;
use App\Modules\Orders\Repositories\QrOrderRequestRepositoryInterface;
use App\Modules\Orders\Support\QrOrderCodeParser;
use App\Modules\Settings\Services\QrOrderingSettingsService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QrOrderReviewService
{
    public function __construct(
        private readonly QrOrderRequestRepositoryInterface $qrOrderRequestRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly QrOrderCodeParser $codeParser,
        private readonly QrOrderExpiryService $qrOrderExpiryService,
        private readonly PosAuditLogService $auditLogService,
        private readonly QrOrderCustomerAuditService $customerAuditService,
        private readonly QrOrderNotificationAdapter $notificationAdapter,
        private readonly QrOrderingSettingsService $qrOrderingSettingsService,
    ) {}

    public function search(User $user, string $rawCode): QrOrderRequest
    {
        $code = $this->codeParser->parse($rawCode);
        if ($code === null) {
            $code = trim($rawCode);
            if ($code === '') {
                throw ValidationException::withMessages([
                    'code' => ['Invalid QR order code or scan payload.'],
                ]);
            }
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $request = QrOrderRequest::query()
            ->where('request_code', $code)
            ->whereIn('outlet_id', $allowed === [] ? [-1] : $allowed)
            ->with(['items.menuItem', 'table', 'order.items'])
            ->first();

        if ($request === null) {
            $request = QrOrderRequest::query()
                ->whereIn('outlet_id', $allowed === [] ? [-1] : $allowed)
                ->whereHas('order', fn ($query) => $query->where('code', $code))
                ->with(['items.menuItem', 'table', 'order.items'])
                ->first();
        }

        if ($request === null) {
            throw (new ModelNotFoundException())->setModel(QrOrderRequest::class, [$code]);
        }

        return $this->qrOrderExpiryService->markExpiredIfNeeded($request);
    }

    public function review(User $user, int $requestId): QrOrderRequest
    {
        $request = $this->resolveScoped($user, $requestId);
        $request = $this->qrOrderExpiryService->markExpiredIfNeeded($request);

        if ((string) $request->status !== 'pending_cashier_confirmation') {
            throw ValidationException::withMessages([
                'request' => ['Only pending QR orders can be reviewed.'],
            ]);
        }

        if ($request->reviewed_at === null) {
            $this->qrOrderRequestRepository->update($request, [
                'reviewed_at' => now(),
                'reviewed_by_user_id' => (int) $user->id,
            ]);
            $this->auditLogService->log(
                'qr_order.reviewed',
                'qr_order_request',
                (int) $request->id,
                (int) $request->outlet_id,
                $user,
            );
            $this->customerAuditService->log('customer_order.reviewed', $request, $user);
            $request = $request->fresh(['items.menuItem', 'table', 'order.items']);
        }

        return $request;
    }

    public function adjust(User $user, int $requestId, array $payload): QrOrderRequest
    {
        return DB::transaction(function () use ($user, $requestId, $payload): QrOrderRequest {
            $request = $this->resolveScoped($user, $requestId, true);
            $request = $this->qrOrderExpiryService->markExpiredIfNeeded($request);

            if ((string) $request->status !== 'pending_cashier_confirmation') {
                throw ValidationException::withMessages([
                    'request' => ['Only pending QR orders can be adjusted.'],
                ]);
            }

            $draftItems = $this->normalizeDraftItems($payload['items'] ?? [], (int) $request->outlet_id);
            $adjustments = $this->buildAdjustments($request, $draftItems, $payload['adjustments'] ?? []);

            $subtotal = collect($draftItems)->sum(fn (array $item): float => (float) $item['qty'] * (float) $item['unitPrice']);
            $promoDiscount = (float) ($payload['promoDiscount'] ?? 0);
            $voucherDiscount = (float) ($payload['voucherDiscount'] ?? 0);
            $loyaltyDiscount = (float) ($payload['loyaltyDiscount'] ?? 0);
            $discount = max(0, $promoDiscount + $voucherDiscount + $loyaltyDiscount);
            $total = max(0, $subtotal - $discount);

            $reviewDraft = [
                'items' => $draftItems,
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'total' => round($total, 2),
                'promo' => $payload['promo'] ?? null,
                'voucher' => $payload['voucher'] ?? null,
                'loyalty' => $payload['loyalty'] ?? null,
                'adjustments' => $adjustments,
            ];

            $history = collect($request->adjustment_log ?? [])
                ->push([
                    'at' => now()->toIso8601String(),
                    'byUserId' => (int) $user->id,
                    'summary' => $adjustments,
                ])
                ->values()
                ->all();

            $approvalStatus = $this->qrOrderingSettingsService->requireCustomerApprovalForAdjustments() && $adjustments !== []
                ? 'pending_approval'
                : null;

            $this->qrOrderRequestRepository->update($request, [
                'reviewed_at' => $request->reviewed_at ?? now(),
                'reviewed_by_user_id' => $request->reviewed_by_user_id ?? (int) $user->id,
                'review_draft' => $reviewDraft,
                'adjustment_log' => $history,
                'customer_approval_status' => $approvalStatus,
            ]);

            $this->auditLogService->log(
                'qr_order.adjusted',
                'qr_order_request',
                (int) $request->id,
                (int) $request->outlet_id,
                $user,
                ['adjustmentCount' => count($adjustments), 'total' => $total],
            );
            $this->customerAuditService->log('customer_order.adjusted', $request, $user, [
                'adjustmentCount' => count($adjustments),
            ]);
            $this->notificationAdapter->qrOrderAdjusted($request);

            return $request->fresh(['items.menuItem', 'table', 'order.items']);
        });
    }

    /** @return list<array<string, mixed>> */
    public function history(User $user, int $requestId): array
    {
        $request = $this->resolveScoped($user, $requestId);
        $events = PosEventLog::query()
            ->where('entity_type', 'qr_order_request')
            ->where('entity_id', (int) $request->id)
            ->orderBy('id')
            ->get();

        $labels = [
            'qr.request.created' => 'QR Order Created',
            'qr_order.reviewed' => 'QR Order Reviewed',
            'qr_order.opened_in_pos' => 'Opened In POS',
            'qr_order.adjusted' => 'QR Order Adjusted',
            'qr.request.confirmed' => 'QR Order Confirmed',
            'qr_order.confirmed' => 'QR Order Confirmed',
            'qr_order.paid' => 'QR Order Paid',
            'qr.request.rejected' => 'QR Order Rejected',
        ];

        return $events->map(fn (PosEventLog $event): array => [
            'eventType' => (string) $event->event_type,
            'label' => $labels[(string) $event->event_type] ?? (string) $event->event_type,
            'occurredAt' => $event->created_at?->toISOString(),
            'payload' => $event->payload ?? [],
        ])->values()->all();
    }

    /** @param list<array<string, mixed>> $items */
    public function resolveOrderItemsFromRequest(QrOrderRequest $request): array
    {
        $draft = is_array($request->review_draft) ? $request->review_draft : null;
        if ($draft !== null && isset($draft['items']) && is_array($draft['items']) && $draft['items'] !== []) {
            return collect($draft['items'])
                ->map(fn (array $item): array => [
                    'id' => (string) ($item['menuItemId'] ?? $item['menu_item_id'] ?? ''),
                    'name' => (string) ($item['name'] ?? 'Item'),
                    'qty' => (float) ($item['qty'] ?? $item['quantity'] ?? 1),
                    'price' => (float) ($item['unitPrice'] ?? $item['price'] ?? 0),
                    'notes' => $item['notes'] ?? $item['note'] ?? null,
                ])
                ->values()
                ->all();
        }

        $requestItems = $request->relationLoaded('items') ? $request->items : $request->items()->with('menuItem')->get();
        $menuItems = MenuItem::query()
            ->whereIn('id', $requestItems->pluck('menu_item_id')->all())
            ->get()
            ->keyBy('id');

        $orderItems = [];
        foreach ($requestItems as $requestItem) {
            $menuItem = $menuItems->get((int) $requestItem->menu_item_id);
            if ($menuItem === null) {
                continue;
            }
            $orderItems[] = [
                'id' => (string) $menuItem->id,
                'name' => (string) $menuItem->name,
                'qty' => (float) $requestItem->qty,
                'price' => (float) $menuItem->price,
                'notes' => $requestItem->notes,
            ];
        }

        return $orderItems;
    }

    public function resolveDiscountAmount(QrOrderRequest $request): float
    {
        $draft = is_array($request->review_draft) ? $request->review_draft : null;
        if ($draft !== null && isset($draft['discount'])) {
            return (float) $draft['discount'];
        }

        return 0.0;
    }

    public function resolveFinancialTotals(QrOrderRequest $request, array $orderItems): array
    {
        $draft = is_array($request->review_draft) ? $request->review_draft : null;
        $subtotal = collect($orderItems)->sum(fn (array $item): float => (float) $item['qty'] * (float) $item['price']);
        $discount = $this->resolveDiscountAmount($request);
        $total = max(0, $subtotal - $discount);

        if ($draft !== null && isset($draft['total'])) {
            $total = (float) $draft['total'];
            $subtotal = (float) ($draft['subtotal'] ?? $subtotal);
            $discount = (float) ($draft['discount'] ?? $discount);
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'total' => round($total, 2),
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function normalizeDraftItems(array $items, int $outletId): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['At least one item is required.'],
            ]);
        }

        $menuItemIds = collect($items)->pluck('menuItemId')->map(fn ($id) => (int) $id)->all();
        $menuItems = MenuItem::query()->whereIn('id', $menuItemIds)->get()->keyBy('id');

        $normalized = [];
        foreach ($items as $item) {
            $menuItemId = (int) ($item['menuItemId'] ?? 0);
            $menuItem = $menuItems->get($menuItemId);
            if ($menuItem === null) {
                throw ValidationException::withMessages([
                    'items' => ['One or more menu items were not found.'],
                ]);
            }
            if ($menuItem->outlet_id !== null && (int) $menuItem->outlet_id !== $outletId) {
                throw ValidationException::withMessages([
                    'items' => ['Menu item does not belong to this outlet.'],
                ]);
            }

            $qty = (float) ($item['qty'] ?? $item['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $unitPrice = (float) ($item['unitPrice'] ?? $menuItem->price);
            $normalized[] = [
                'menuItemId' => $menuItemId,
                'name' => (string) $menuItem->name,
                'qty' => $qty,
                'unitPrice' => $unitPrice,
                'lineTotal' => round($qty * $unitPrice, 2),
                'notes' => $item['notes'] ?? $item['note'] ?? null,
            ];
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'items' => ['At least one item with quantity is required.'],
            ]);
        }

        return $normalized;
    }

    /** @param list<array<string, mixed>> $draftItems @return list<array<string, mixed>> */
    private function buildAdjustments(QrOrderRequest $request, array $draftItems, array $explicit): array
    {
        if ($explicit !== []) {
            return array_values($explicit);
        }

        $original = $request->items
            ->map(fn ($item): string => (int) $item->menu_item_id.'|'.number_format((float) $item->qty, 3, '.', ''))
            ->sort()
            ->values()
            ->all();
        $draft = collect($draftItems)
            ->map(fn (array $item): string => (int) $item['menuItemId'].'|'.number_format((float) $item['qty'], 3, '.', ''))
            ->sort()
            ->values()
            ->all();

        if ($original === $draft) {
            return [];
        }

        return [['type' => 'modified', 'message' => 'Order items updated by cashier']];
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
