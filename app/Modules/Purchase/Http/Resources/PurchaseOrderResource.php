<?php

namespace App\Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'poNumber' => $this->number,
            'supplierId' => (string) $this->supplier_id,
            'date' => optional($this->order_date)->format('Y-m-d'),
            'referencePR' => $this->purchaseRequest?->number,
            'purchaseRequestId' => $this->purchase_request_id ? (string) $this->purchase_request_id : null,
            'sourcePrId' => $this->source_pr_id ? (string) $this->source_pr_id : null,
            'status' => $this->status,
            'notes' => $this->notes,
            'items' => $this->items->map(static fn ($item): array => [
                'id' => (string) $item->id,
                'inventoryItemId' => (string) $item->ingredient_id,
                'qty' => (float) $item->ordered_qty,
                'prItemId' => $item->pr_item_id ? (string) $item->pr_item_id : null,
                'requestedQty' => (float) ($item->requested_qty ?? 0),
                'isFromPr' => (bool) ($item->is_from_pr ?? false),
                'unit' => null,
                'price' => (float) $item->unit_price,
                'receivedQty' => (float) $item->received_qty,
            ])->values(),
            'createdAt' => optional($this->created_at)->toISOString(),
        ];
    }
}
