<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderVoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'orderId' => (string) $this->order_id,
            'memberVoucherId' => (string) $this->member_voucher_id,
            'voucherId' => (string) $this->voucher_id,
            'voucherCode' => (string) $this->voucher_code,
            'discountType' => (string) $this->discount_type,
            'discountValue' => (float) $this->discount_value,
            'discountAmount' => (float) $this->discount_amount,
            'appliedAt' => $this->applied_at?->toISOString(),
            'voucherName' => $this->whenLoaded('voucher', fn () => $this->voucher?->name),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
