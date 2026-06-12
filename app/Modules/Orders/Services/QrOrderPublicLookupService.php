<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Orders\Support\QrOrderCodeParser;
use App\Modules\Settings\Services\QrOrderingSettingsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class QrOrderPublicLookupService
{
    public function __construct(
        private readonly QrOrderCodeParser $codeParser,
        private readonly QrOrderCustomerStatusService $customerStatusService,
        private readonly QrOrderCustomerTimelineService $timelineService,
        private readonly QrOrderExpiryService $qrOrderExpiryService,
        private readonly QrOrderPosIntegrationService $qrOrderPosIntegrationService,
        private readonly QrOrderingSettingsService $qrOrderingSettingsService,
    ) {}

    /** @return array<string, mixed> */
    public function findByOrderCode(string $orderCode): array
    {
        $normalized = $this->codeParser->normalizePublicLookupCode($orderCode);
        if ($normalized === null) {
            throw (new ModelNotFoundException())->setModel(QrOrderRequest::class, [trim($orderCode)]);
        }

        $request = QrOrderRequest::query()
            ->where('request_code', $normalized)
            ->with(['items.menuItem', 'table', 'order.items'])
            ->first();

        if ($request === null) {
            throw (new ModelNotFoundException())->setModel(QrOrderRequest::class, [$normalized]);
        }

        $request = $this->qrOrderExpiryService->markExpiredIfNeeded($request);
        $status = $this->customerStatusService->resolve($request);
        $financials = $this->resolveFinancials($request);
        $outletName = Outlet::query()->whereKey((int) $request->outlet_id)->value('name');

        $adjustments = $this->resolveAdjustments($request, $financials);
        $isAdjusted = $adjustments !== [] || $status['customerStatus'] === 'adjusted';
        $linkedOrder = $this->resolveLinkedOrder($request);
        $openBill = $this->resolveOpenBill($request, $linkedOrder);

        return [
            'orderCode' => (string) $request->request_code,
            'tableName' => (string) ($request->table?->name ?? ''),
            'outletName' => (string) ($outletName ?? ''),
            'tableQrPublicId' => $request->table?->qr_public_id,
            'status' => $status['status'],
            'customerStatus' => $status['customerStatus'],
            'customerStatusLabel' => $status['customerStatusLabel'],
            'timelineStep' => $status['timelineStep'],
            'isTerminal' => $status['isTerminal'],
            'timeline' => $this->timelineService->build($request),
            'items' => $financials['items'],
            'subtotal' => $financials['subtotal'],
            'discount' => $financials['discount'],
            'promo' => $financials['promo'],
            'tax' => $financials['tax'],
            'service' => $financials['service'],
            'total' => $financials['total'],
            'adjustments' => $adjustments,
            'removedItems' => collect($adjustments)
                ->filter(fn (array $row): bool => ($row['type'] ?? '') === 'removed')
                ->values()
                ->all(),
            'addedItems' => collect($adjustments)
                ->filter(fn (array $row): bool => ($row['type'] ?? '') === 'added')
                ->values()
                ->all(),
            'promoLabel' => $financials['promoLabel'],
            'isAdjustedByCashier' => $isAdjusted,
            'awaitingCustomerApproval' => (string) ($request->customer_approval_status ?? '') === 'pending_approval',
            'customerMessage' => $isAdjusted
                ? 'Pesanan Anda telah diperbarui oleh kasir. Mohon cek detail pesanan.'
                : null,
            'linkedPosOrder' => $linkedOrder,
            'openBill' => $openBill,
            'paymentStatus' => $linkedOrder['paymentStatus'] ?? null,
            'createdAt' => $request->created_at?->toISOString(),
            'updatedAt' => $request->updated_at?->toISOString(),
            'qrOrdering' => $this->qrOrderingSettingsService->publicQrOrderingConfig(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function resolveAdjustments(QrOrderRequest $request, array $financials): array
    {
        if ($request->order !== null && is_array($request->original_items_snapshot) && $request->original_items_snapshot !== []) {
            return $this->qrOrderPosIntegrationService->buildPosAdjustments($request, $request->order);
        }

        $draft = is_array($request->review_draft) ? $request->review_draft : null;
        if ($draft !== null && is_array($draft['adjustments'] ?? null)) {
            return collect($draft['adjustments'])
                ->map(function (array $row): array {
                    return [
                        'type' => (string) ($row['type'] ?? 'changed'),
                        'name' => $row['name'] ?? $row['itemName'] ?? null,
                        'reason' => $row['reason'] ?? null,
                        'from' => isset($row['original']['qty']) ? (string) $row['original']['qty'] : ($row['from'] ?? null),
                        'to' => isset($row['updated']['qty']) ? (string) $row['updated']['qty'] : ($row['to'] ?? null),
                        'message' => $row['message'] ?? null,
                    ];
                })
                ->values()
                ->all();
        }

        return [];
    }

    /** @return array<string, mixed>|null */
    private function resolveLinkedOrder(QrOrderRequest $request): ?array
    {
        if ($request->order === null) {
            return null;
        }

        $order = $request->order;

        return [
            'id' => (int) $order->id,
            'orderCode' => (string) $order->code,
            'status' => (string) $order->status,
            'paymentStatus' => (string) $order->payment_status,
            'kitchenStatus' => (string) ($order->kitchen_status ?? 'queued'),
            'total' => (float) $order->total,
            'sourceCode' => (string) ($order->source_code ?? $request->request_code),
        ];
    }

    /** @param array<string, mixed>|null $linkedOrder */
    private function resolveOpenBill(QrOrderRequest $request, ?array $linkedOrder): ?array
    {
        if ($linkedOrder === null) {
            return null;
        }

        $paymentStatus = (string) ($linkedOrder['paymentStatus'] ?? 'unpaid');
        $label = match ($paymentStatus) {
            'paid' => 'Paid',
            'partial' => 'Partial',
            default => 'Unpaid',
        };

        return [
            'status' => $label,
            'paymentStatus' => $paymentStatus,
            'total' => (float) ($linkedOrder['total'] ?? 0),
            'orderCode' => (string) ($linkedOrder['orderCode'] ?? ''),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, subtotal: float, discount: float, promo: float, tax: float, service: float, total: float, promoLabel: string|null} */
    private function resolveFinancials(QrOrderRequest $request): array
    {
        if (in_array((string) $request->status, ['confirmed', 'paid'], true) && $request->order !== null) {
            $order = $request->order;
            $items = $order->items
                ->map(fn ($item): array => [
                    'name' => (string) $item->name,
                    'quantity' => (float) $item->qty,
                    'note' => $item->notes,
                    'unitPrice' => (float) $item->price,
                    'lineTotal' => (float) ($item->line_total ?? ((float) $item->qty * (float) $item->price)),
                ])
                ->values()
                ->all();

            $draft = is_array($request->review_draft) ? $request->review_draft : null;
            $promoLabel = is_array($draft['promo'] ?? null) ? ($draft['promo']['name'] ?? $draft['promo']['code'] ?? null) : null;

            return [
                'items' => $items,
                'subtotal' => (float) ($order->subtotal ?? $order->total),
                'discount' => (float) ($order->discount_amount ?? 0),
                'promo' => (float) ($order->discount_amount ?? 0),
                'tax' => (float) ($order->tax ?? 0),
                'service' => 0.0,
                'total' => (float) $order->total,
                'promoLabel' => is_string($promoLabel) ? $promoLabel : null,
            ];
        }

        $draft = is_array($request->review_draft) ? $request->review_draft : null;
        if ($draft !== null && is_array($draft['items'] ?? null) && $draft['items'] !== []) {
            $items = collect($draft['items'])
                ->map(function (array $item): array {
                    $qty = (float) ($item['qty'] ?? $item['quantity'] ?? 1);
                    $unitPrice = (float) ($item['unitPrice'] ?? $item['price'] ?? 0);

                    return [
                        'name' => (string) ($item['name'] ?? 'Item'),
                        'quantity' => $qty,
                        'note' => $item['notes'] ?? $item['note'] ?? null,
                        'unitPrice' => $unitPrice,
                        'lineTotal' => $qty * $unitPrice,
                    ];
                })
                ->values()
                ->all();

            return [
                'items' => $items,
                'subtotal' => (float) ($draft['subtotal'] ?? collect($items)->sum(fn (array $row): float => (float) $row['lineTotal'])),
                'discount' => (float) ($draft['discount'] ?? 0),
                'promo' => (float) ($draft['discount'] ?? 0),
                'tax' => 0.0,
                'service' => 0.0,
                'total' => (float) ($draft['total'] ?? 0),
                'promoLabel' => is_array($draft['promo'] ?? null) ? ($draft['promo']['name'] ?? null) : null,
            ];
        }

        $items = $request->items
            ->map(function ($item): array {
                $unitPrice = (float) ($item->menuItem?->price ?? 0);
                $qty = (float) $item->qty;

                return [
                    'name' => (string) ($item->menuItem?->name ?? 'Item'),
                    'quantity' => $qty,
                    'note' => $item->notes,
                    'unitPrice' => $unitPrice,
                    'lineTotal' => $qty * $unitPrice,
                ];
            })
            ->values()
            ->all();

        $subtotal = collect($items)->sum(fn (array $row): float => (float) $row['lineTotal']);

        return [
            'items' => $items,
            'subtotal' => (float) $subtotal,
            'discount' => 0.0,
            'promo' => 0.0,
            'tax' => 0.0,
            'service' => 0.0,
            'total' => (float) $subtotal,
            'promoLabel' => null,
        ];
    }
}
