<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'source' => $this->source,
            'orderType' => $this->order_type,
            'status' => $this->status,
            'subtotal' => (float) $this->subtotal,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'discountAmount' => (float) ($this->discount_amount ?? 0),
            'paymentStatus' => $this->payment_status,
            'isPosted' => (bool) $this->is_posted,
            'customerName' => $this->customer_name,
            'customerPhone' => $this->customer_phone,
            'tableNumber' => $this->table_number,
            'splitBill' => $this->split_bill,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'orderItemId' => (string) $item->id,
                'id' => $item->item_id,
                'name' => $item->name,
                'emoji' => $item->emoji,
                'qty' => (float) $item->qty,
                'price' => (float) $item->price,
                'notes' => $item->notes,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'method' => $payment->method,
                'amount' => (float) $payment->amount,
                'paidAt' => $payment->paid_at?->toISOString(),
                'allocations' => $payment->relationLoaded('allocations')
                    ? $payment->allocations->map(fn ($allocation) => [
                        'orderItemId' => $allocation->order_item_id,
                        'qty' => (float) $allocation->qty,
                        'amount' => (float) $allocation->amount,
                    ])->values()
                    : [],
            ])),
            'createdAt' => $this->created_at?->toISOString(),
            'confirmedAt' => $this->confirmed_at?->toISOString(),
        ];
    }
}
