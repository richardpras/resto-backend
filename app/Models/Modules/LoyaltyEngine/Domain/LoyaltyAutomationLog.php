<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyAutomationLog extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'automation_id',
        'member_id',
        'trigger_type',
        'action_type',
        'status',
        'result_json',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'automation_id' => 'integer',
            'member_id' => 'integer',
            'result_json' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAutomation::class, 'automation_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
