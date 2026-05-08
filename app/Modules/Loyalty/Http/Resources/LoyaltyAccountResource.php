<?php

namespace App\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Loyalty\Domain\LoyaltyAccount */
class LoyaltyAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'customerUuid' => (string) $this->customer_uuid,
            'globalCustomerUuid' => (string) $this->global_customer_uuid,
            'name' => (string) $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'pointsBalance' => (int) $this->points_balance,
            'lifetimePointsEarned' => (int) $this->lifetime_points_earned,
            'lifetimePointsRedeemed' => (int) $this->lifetime_points_redeemed,
            'lifetimeSpend' => (float) $this->lifetime_spend,
            'lifetimeVisits' => (int) $this->lifetime_visits,
            'currentTier' => $this->currentTier ? [
                'id' => (int) $this->currentTier->id,
                'name' => (string) $this->currentTier->name,
                'multiplier' => (float) $this->currentTier->points_multiplier,
            ] : null,
            'lastActivityAt' => $this->last_activity_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
