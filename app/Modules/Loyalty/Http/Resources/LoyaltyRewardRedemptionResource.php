<?php

namespace App\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Loyalty\Domain\LoyaltyRewardRedemption */
class LoyaltyRewardRedemptionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'customerId' => (int) $this->loyalty_account_id,
            'outletId' => (int) $this->outlet_id,
            'idempotencyKey' => (string) $this->idempotency_key,
            'rewardCode' => (string) $this->reward_code,
            'pointsCost' => (int) $this->points_cost,
            'status' => (string) $this->status,
            'redeemedAt' => $this->redeemed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
