<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Member;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRewardRedemption extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'loyalty_reward_redemptions';

    protected $fillable = [
        'outlet_id',
        'member_id',
        'reward_id',
        'points_spent',
        'status',
        'issued_at',
        'fulfilled_at',
        'cancelled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'member_id' => 'integer',
            'reward_id' => 'integer',
            'points_spent' => 'integer',
            'issued_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'reward_id');
    }
}
