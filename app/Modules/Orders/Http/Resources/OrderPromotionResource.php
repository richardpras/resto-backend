<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderPromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'promotionId' => $this->promotion_id !== null ? (string) $this->promotion_id : null,
            'promotionCode' => $this->promotion_code,
            'promotionName' => $this->promotion_name,
            'discountType' => $this->discount_type,
            'discountValue' => (float) $this->discount_value,
            'discountAmount' => (float) $this->discount_amount,
            'appliedItems' => $this->applied_items ?? [],
            'appliedAt' => $this->applied_at?->toISOString(),
        ];
    }
}
