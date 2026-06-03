<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyMemberLedger extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_EARN = 'earn';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_REWARD_REDEEM = 'reward_redeem';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_VISIT_REWARD = 'visit_reward';

    public const TYPE_PERIOD_REWARD = 'period_reward';

    public const TYPE_EXPIRED = 'expired';

    protected $table = 'loyalty_member_ledger';

    protected $fillable = [
        'member_id',
        'loyalty_program_id',
        'type',
        'reference_type',
        'reference_id',
        'points',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }
}
