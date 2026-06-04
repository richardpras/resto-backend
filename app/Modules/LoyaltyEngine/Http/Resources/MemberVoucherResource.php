<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberVoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => (int) $this->outlet_id,
            'memberId' => (string) $this->member_id,
            'voucherId' => (string) $this->voucher_id,
            'voucherCode' => (string) $this->voucher_code,
            'status' => (string) $this->status,
            'issuedAt' => $this->issued_at?->toIso8601String(),
            'claimedAt' => $this->claimed_at?->toIso8601String(),
            'redeemedAt' => $this->redeemed_at?->toIso8601String(),
            'expiredAt' => $this->expired_at?->toIso8601String(),
            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            'notes' => $this->notes,
            'voucher' => $this->whenLoaded('voucher', fn () => new LoyaltyVoucherResource($this->voucher)),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
