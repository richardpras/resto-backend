<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LoyaltyProgram extends Model
{
    public const TYPE_SPEND_BASED = 'spend_based';

    public const TYPE_PERIOD_SPENDING = 'period_spending';

    public const TYPE_VISIT_BASED = 'visit_based';

    public const TYPE_MANUAL = 'manual';

    public const TYPE_PERCENTAGE_REWARD = 'percentage_reward';

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'description',
        'type',
        'is_active',
        'expiry_enabled',
        'expiry_days',
        'effective_from',
        'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expiry_enabled' => 'boolean',
            'expiry_days' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function isEffectiveAt(?\DateTimeInterface $at = null): bool
    {
        $moment = $at !== null ? \Carbon\Carbon::instance($at) : now();

        if ($this->effective_from !== null && $moment->lt($this->effective_from->startOfDay())) {
            return false;
        }

        if ($this->effective_until !== null && $moment->gt($this->effective_until->endOfDay())) {
            return false;
        }

        return true;
    }

    public function rules(): HasMany
    {
        return $this->hasMany(LoyaltyProgramRule::class);
    }

    public function activeRule(): HasOne
    {
        return $this->hasOne(LoyaltyProgramRule::class)->latestOfMany();
    }
}
