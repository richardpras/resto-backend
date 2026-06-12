<?php

namespace App\Modules\Orders\Http\Resources;

use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Modules\Orders\Services\QrOrderReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QrOrderReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var QrOrderRequest $requestModel */
        $requestModel = $this->resource;
        $reviewService = app(QrOrderReviewService::class);

        $draft = is_array($requestModel->review_draft) ? $requestModel->review_draft : null;
        $activeItems = $draft['items'] ?? null;
        $originalItems = $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
            'id' => (string) $item->id,
            'menuItemId' => (int) $item->menu_item_id,
            'name' => (string) ($item->menuItem?->name ?? 'Item'),
            'qty' => (float) $item->qty,
            'unitPrice' => (float) ($item->menuItem?->price ?? 0),
            'lineTotal' => round((float) $item->qty * (float) ($item->menuItem?->price ?? 0), 2),
            'notes' => $item->notes,
        ])->values(), []);

        $displayItems = $activeItems ?? $originalItems;
        $orderItems = $reviewService->resolveOrderItemsFromRequest($requestModel);
        $financials = $reviewService->resolveFinancialTotals($requestModel, $orderItems);

        return [
            'id' => (string) $requestModel->id,
            'requestCode' => (string) $requestModel->request_code,
            'outletId' => (int) $requestModel->outlet_id,
            'tableId' => (int) $requestModel->table_id,
            'tableName' => $requestModel->table?->name,
            'customerName' => $requestModel->customer_name,
            'status' => (string) $requestModel->status,
            'reviewedAt' => $requestModel->reviewed_at?->toISOString(),
            'originalItems' => $originalItems,
            'items' => $displayItems,
            'adjustments' => $draft['adjustments'] ?? [],
            'promo' => $draft['promo'] ?? null,
            'voucher' => $draft['voucher'] ?? null,
            'loyalty' => $draft['loyalty'] ?? null,
            'subtotal' => $financials['subtotal'],
            'discount' => $financials['discount'],
            'total' => $financials['total'],
            'hasAdjustments' => $draft !== null && ($draft['adjustments'] ?? []) !== [],
            'createdAt' => $requestModel->created_at?->toISOString(),
            'updatedAt' => $requestModel->updated_at?->toISOString(),
        ];
    }
}
