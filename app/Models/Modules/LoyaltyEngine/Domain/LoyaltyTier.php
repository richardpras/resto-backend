<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyTier extends Model
{
    public const TYPE_LIFETIME_POINTS = 'lifetime_points';

    public const TYPE_LIFETIME_SPENDING = 'lifetime_spending';

    public const TYPE_VISIT_COUNT = 'visit_count';

    /** @var list<string> */
    public const QUALIFICATION_TYPES = [
        self::TYPE_LIFETIME_POINTS,
        self::TYPE_LIFETIME_SPENDING,
        self::TYPE_VISIT_COUNT,
    ];

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'description',
        'qualification_type',
        'qualification_config',
        'benefit_config_json',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'qualification_config' => 'array',
            'benefit_config_json' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function memberTierHistories(): HasMany
    {
        return $this->hasMany(MemberTierHistory::class, 'tier_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function qualificationConfig(): array
    {
        return is_array($this->qualification_config) ? $this->qualification_config : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function benefitConfig(): array
    {
        return is_array($this->benefit_config_json) ? $this->benefit_config_json : [];
    }
}
