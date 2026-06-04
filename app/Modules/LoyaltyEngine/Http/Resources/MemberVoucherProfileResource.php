<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberVoucherProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'voucherCode' => (string) $this->voucher_code,
            'name' => (string) ($this->voucher?->name ?? ''),
            'status' => (string) $this->status,
            'valueType' => (string) ($this->voucher?->value_type ?? ''),
            'value' => (float) ($this->voucher?->value ?? 0),
            'issuedAt' => $this->issued_at?->toIso8601String(),
            'claimedAt' => $this->claimed_at?->toIso8601String(),
            'redeemedAt' => $this->redeemed_at?->toIso8601String(),
            'expiredAt' => $this->expired_at?->toIso8601String(),
            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
