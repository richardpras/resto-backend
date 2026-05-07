<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderSplitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'orderId' => (int) $this->order_id,
            'splitType' => (string) $this->split_type,
            'label' => (string) $this->label,
            'status' => (string) $this->status,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'orderItemId' => (int) $item->order_item_id,
                'qty' => (float) $item->qty,
                'amount' => (float) $item->amount,
            ])->values()),
        ];
    }
}
