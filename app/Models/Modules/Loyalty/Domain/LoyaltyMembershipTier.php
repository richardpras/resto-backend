<?php

namespace App\Models\Modules\Loyalty\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyMembershipTier extends Model
{
    protected $fillable = [
        'outlet_id',
        'name',
        'code',
        'priority',
        'min_lifetime_spend',
        'min_lifetime_visits',
        'points_multiplier',
        'benefits',
        'is_active',
    ];

    protected $casts = [
        'min_lifetime_spend' => 'decimal:2',
        'points_multiplier' => 'decimal:4',
        'benefits' => 'array',
        'is_active' => 'boolean',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(LoyaltyAccount::class, 'current_tier_id');
    }
}
