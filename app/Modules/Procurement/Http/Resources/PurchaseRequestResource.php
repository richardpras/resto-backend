<?php

namespace App\Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'prNumber' => $this->request_no,
            'requestNo' => $this->request_no,
            'outletId' => (string) $this->outlet_id,
            'outlet' => $this->outlet?->name ?? (string) $this->outlet_id,
            'date' => optional($this->created_at)->format('Y-m-d'),
            'requestedBy' => $this->requested_by,
            'approvedBy' => $this->approved_by ? (string) $this->approved_by : null,
            'status' => $this->status,
            'notes' => $this->notes,
            'submittedAt' => optional($this->submitted_at)->toISOString(),
            'approvedAt' => optional($this->approved_at)->toISOString(),
            'rejectedAt' => optional($this->rejected_at)->toISOString(),
            'items' => $this->items->map(static fn ($item): array => [
                'id' => (string) $item->id,
                'inventoryItemId' => (string) $item->inventory_item_id,
                'qty' => (float) $item->quantity,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit ?? '',
                'estimatedCost' => $item->estimated_cost !== null ? (float) $item->estimated_cost : null,
                'notes' => $item->notes,
            ])->values(),
            'createdAt' => optional($this->created_at)->toISOString(),
            'updatedAt' => optional($this->updated_at)->toISOString(),
        ];
    }
}
