<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'orderId' => (int) $this->order_id,
            'orderSplitId' => $this->order_split_id !== null ? (int) $this->order_split_id : null,
            'method' => (string) $this->method,
            'amount' => (float) $this->amount,
            'tenderedAmount' => $this->tendered_amount !== null ? (float) $this->tendered_amount : null,
            'changeAmount' => $this->change_amount !== null ? (float) $this->change_amount : null,
            'status' => (string) $this->status,
            'paidAt' => $this->paid_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'splitBillLabel' => $this->split_bill_label,
            'splitBillGroup' => $this->split_bill_group,
            'splitLabel' => $this->whenLoaded('split', fn () => $this->split !== null ? (string) $this->split->label : null),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation) => [
                'orderItemId' => (int) $allocation->order_item_id,
                'qty' => (float) $allocation->qty,
                'amount' => (float) $allocation->amount,
            ])->values()),
        ];
    }
}
