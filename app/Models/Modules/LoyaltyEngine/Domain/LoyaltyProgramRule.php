<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyProgramRule extends Model
{
    public const TYPE_SPEND_BASED = 'spend_based';

    public const TYPE_VISIT_BASED = 'visit_based';

    public const TYPE_PERIOD_SPENDING = 'period_spending';

    public const TYPE_PERCENTAGE_REWARD = 'percentage_reward';

    public const RULE_TYPES = [
        self::TYPE_SPEND_BASED,
        self::TYPE_VISIT_BASED,
        self::TYPE_PERIOD_SPENDING,
        self::TYPE_PERCENTAGE_REWARD,
    ];

    protected $fillable = [
        'loyalty_program_id',
        'rule_type',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }
}
