<?php

namespace App\Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'grnNumber' => $this->number,
            'poReference' => $this->purchaseOrder?->number,
            'purchaseOrderId' => $this->purchase_order_id ? (string) $this->purchase_order_id : null,
            'date' => optional($this->received_date)->format('Y-m-d'),
            'status' => 'received',
            'items' => $this->items->map(static fn ($item): array => [
                'id' => (string) $item->id,
                'inventoryItemId' => (string) $item->ingredient_id,
                'orderedQty' => (float) ($item->purchaseOrderItem?->ordered_qty ?? 0),
                'receivedQty' => (float) $item->received_qty,
                'unit' => null,
            ])->values(),
            'createdAt' => optional($this->created_at)->toISOString(),
        ];
    }
}
