<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyVoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => (int) $this->outlet_id,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'description' => $this->description,
            'voucherType' => (string) $this->voucher_type,
            'valueType' => (string) $this->value_type,
            'value' => (float) $this->value,
            'minimumSpend' => (float) $this->minimum_spend,
            'validFrom' => $this->valid_from?->toIso8601String(),
            'validUntil' => $this->valid_until?->toIso8601String(),
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
