<?php

namespace App\Modules\Loyalty\Services;

use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Loyalty\Domain\LoyaltyMembershipTier;
use App\Modules\Loyalty\Events\MembershipTierChanged;

class MembershipTierService
{
    public function evaluateAndApply(LoyaltyAccount $account): LoyaltyAccount
    {
        $candidate = LoyaltyMembershipTier::query()
            ->where('is_active', true)
            ->where(function ($query) use ($account): void {
                $query->where('outlet_id', $account->outlet_id)
                    ->orWhereNull('outlet_id');
            })
            ->where('min_lifetime_spend', '<=', $account->lifetime_spend)
            ->where('min_lifetime_visits', '<=', $account->lifetime_visits)
            ->orderByDesc('min_lifetime_spend')
            ->orderByDesc('min_lifetime_visits')
            ->orderByDesc('priority')
            ->first();

        $previousTierId = $account->current_tier_id !== null ? (int) $account->current_tier_id : null;
        $nextTierId = $candidate?->id !== null ? (int) $candidate->id : null;

        if ($previousTierId !== $nextTierId) {
            $account->current_tier_id = $nextTierId;
            $account->save();

            event(new MembershipTierChanged(
                (int) $account->outlet_id,
                (int) $account->id,
                $previousTierId,
                $nextTierId,
                $candidate?->name,
                null,
                $account->updated_at?->toIso8601String(),
            ));
        }

        return $account->fresh() ?? $account;
    }

    public function multiplierFor(?LoyaltyMembershipTier $tier): float
    {
        if ($tier === null) {
            return 1.0;
        }

        return max(1.0, (float) $tier->points_multiplier);
    }
}
