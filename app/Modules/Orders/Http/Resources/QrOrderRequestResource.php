<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QrOrderRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'requestCode' => (string) $this->request_code,
            'outletId' => (int) $this->outlet_id,
            'tableId' => (int) $this->table_id,
            'tableName' => $this->table?->name,
            'customerName' => $this->customer_name,
            'status' => (string) $this->status,
            'expiresAt' => $this->expires_at?->toISOString(),
            'confirmedAt' => $this->confirmed_at?->toISOString(),
            'rejectedAt' => $this->rejected_at?->toISOString(),
            'rejectionReason' => $this->rejection_reason,
            'orderId' => $this->order_id !== null ? (string) $this->order_id : null,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'menuItemId' => (int) $item->menu_item_id,
                'qty' => (float) $item->qty,
                'notes' => $item->notes,
            ])->values()),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
