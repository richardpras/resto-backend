<?php

namespace App\Modules\Orders\Http\Resources;

use App\Models\Modules\Orders\Domain\QrOrderRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QrOrderPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var QrOrderRequest $requestModel */
        $requestModel = $this->resource;

        $items = $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
            'id' => (string) $item->id,
            'menuItemId' => (int) $item->menu_item_id,
            'name' => (string) ($item->menuItem?->name ?? 'Item'),
            'qty' => (float) $item->qty,
            'unitPrice' => (float) ($item->menuItem?->price ?? 0),
            'lineTotal' => round((float) $item->qty * (float) ($item->menuItem?->price ?? 0), 2),
            'notes' => $item->notes,
        ])->values(), []);

        $subtotal = collect($items)->sum(fn (array $item): float => (float) ($item['lineTotal'] ?? 0));
        $customerNotes = collect($items)
            ->pluck('notes')
            ->filter(fn ($note): bool => is_string($note) && trim($note) !== '')
            ->values()
            ->all();

        return [
            'id' => (string) $requestModel->id,
            'requestCode' => (string) $requestModel->request_code,
            'outletId' => (int) $requestModel->outlet_id,
            'tableId' => (int) $requestModel->table_id,
            'tableName' => $requestModel->table?->name,
            'customerName' => $requestModel->customer_name,
            'customerNotes' => $customerNotes,
            'status' => (string) $requestModel->status,
            'items' => $items,
            'subtotal' => round((float) $subtotal, 2),
            'discount' => 0.0,
            'total' => round((float) $subtotal, 2),
            'createdAt' => $requestModel->created_at?->toISOString(),
            'openedInPosAt' => $requestModel->opened_in_pos_at?->toISOString(),
        ];
    }
}
