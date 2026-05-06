<?php

namespace App\Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'prNumber' => $this->number,
            'date' => optional($this->request_date)->format('Y-m-d'),
            'outlet' => $this->outlet ?? '',
            'requestedBy' => $this->requested_by ?? '',
            'status' => $this->status,
            'notes' => $this->notes,
            'items' => $this->items->map(static fn ($item): array => [
                'id' => (string) $item->id,
                'inventoryItemId' => (string) $item->ingredient_id,
                'qty' => (float) $item->requested_qty,
                'fulfilledQty' => (float) ($item->fulfilled_qty ?? 0),
                'remainingQty' => max(0, (float) $item->requested_qty - (float) ($item->fulfilled_qty ?? 0)),
                'unit' => $item->unit ?? '',
                'notes' => null,
            ])->values(),
            'createdAt' => optional($this->created_at)->toISOString(),
        ];
    }
}
