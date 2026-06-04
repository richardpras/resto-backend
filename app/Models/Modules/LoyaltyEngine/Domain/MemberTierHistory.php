<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Member;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberTierHistory extends Model
{
    public const REASON_AUTO_UPGRADE = 'auto_upgrade';

    public const REASON_MANUAL_ASSIGN = 'manual_assign';

    public const REASON_RECALCULATION = 'recalculation';

    /** @var list<string> */
    public const REASONS = [
        self::REASON_AUTO_UPGRADE,
        self::REASON_MANUAL_ASSIGN,
        self::REASON_RECALCULATION,
    ];

    protected $fillable = [
        'outlet_id',
        'member_id',
        'tier_id',
        'assigned_at',
        'removed_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'member_id' => 'integer',
            'tier_id' => 'integer',
            'assigned_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'tier_id');
    }
}
