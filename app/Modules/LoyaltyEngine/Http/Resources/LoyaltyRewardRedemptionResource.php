<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyRewardRedemptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => (int) $this->outlet_id,
            'memberId' => (string) $this->member_id,
            'rewardId' => (string) $this->reward_id,
            'rewardName' => $this->relationLoaded('reward') && $this->reward !== null
                ? (string) $this->reward->name
                : null,
            'rewardCode' => $this->relationLoaded('reward') && $this->reward !== null
                ? (string) $this->reward->code
                : null,
            'pointsSpent' => (int) $this->points_spent,
            'status' => (string) $this->status,
            'issuedAt' => $this->issued_at?->toIso8601String(),
            'fulfilledAt' => $this->fulfilled_at?->toIso8601String(),
            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
