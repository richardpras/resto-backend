<?php

namespace App\Modules\Kitchen\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KitchenTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'orderId' => (int) $this->order_id,
            'ticketNo' => (string) $this->ticket_no,
            'status' => (string) $this->status,
            'queuedAt' => $this->queued_at?->toISOString(),
            'startedAt' => $this->started_at?->toISOString(),
            'readyAt' => $this->ready_at?->toISOString(),
            'servedAt' => $this->served_at?->toISOString(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'orderItemId' => (int) $item->order_item_id,
                'name' => (string) $item->item_name_snapshot,
                'qty' => (float) $item->qty,
                'notes' => $item->notes,
                'status' => (string) $item->status,
            ])->values()),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
