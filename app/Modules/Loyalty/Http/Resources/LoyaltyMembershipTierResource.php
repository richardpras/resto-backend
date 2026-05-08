<?php

namespace App\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Loyalty\Domain\LoyaltyMembershipTier */
class LoyaltyMembershipTierResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => $this->outlet_id !== null ? (int) $this->outlet_id : null,
            'name' => (string) $this->name,
            'code' => $this->code,
            'priority' => (int) $this->priority,
            'minLifetimeSpend' => (float) $this->min_lifetime_spend,
            'minLifetimeVisits' => (int) $this->min_lifetime_visits,
            'pointsMultiplier' => (float) $this->points_multiplier,
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
