<?php

namespace App\Models\Modules\Loyalty\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyAccount extends Model
{
    protected $fillable = [
        'outlet_id',
        'customer_uuid',
        'global_customer_uuid',
        'name',
        'phone',
        'email',
        'points_balance',
        'lifetime_points_earned',
        'lifetime_points_redeemed',
        'lifetime_spend',
        'lifetime_visits',
        'current_tier_id',
        'merged_into_account_id',
        'last_activity_at',
    ];

    protected $casts = [
        'points_balance' => 'integer',
        'lifetime_points_earned' => 'integer',
        'lifetime_points_redeemed' => 'integer',
        'lifetime_spend' => 'decimal:2',
        'lifetime_visits' => 'integer',
        'last_activity_at' => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function currentTier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyMembershipTier::class, 'current_tier_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_account_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(LoyaltyPointsLedger::class, 'loyalty_account_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRewardRedemption::class, 'loyalty_account_id');
    }
}
