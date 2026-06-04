<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyAutomation extends Model
{
    public const TRIGGER_MEMBER_BIRTHDAY = 'member_birthday';

    public const TRIGGER_MEMBER_CREATED = 'member_created';

    public const TRIGGER_TIER_UPGRADED = 'tier_upgraded';

    public const TRIGGER_VISIT_MILESTONE = 'visit_milestone';

    public const TRIGGER_POINTS_MILESTONE = 'points_milestone';

    public const TRIGGER_INACTIVE_MEMBER = 'inactive_member';

    public const TRIGGER_VOUCHER_REDEEMED = 'voucher_redeemed';

    public const TRIGGER_REWARD_REDEEMED = 'reward_redeemed';

    public const ACTION_ISSUE_VOUCHER = 'issue_voucher';

    public const ACTION_SEND_NOTIFICATION = 'send_notification';

    public const ACTION_ASSIGN_CAMPAIGN = 'assign_campaign';

    /** @var list<string> */
    public const TRIGGER_TYPES = [
        self::TRIGGER_MEMBER_BIRTHDAY,
        self::TRIGGER_MEMBER_CREATED,
        self::TRIGGER_TIER_UPGRADED,
        self::TRIGGER_VISIT_MILESTONE,
        self::TRIGGER_POINTS_MILESTONE,
        self::TRIGGER_INACTIVE_MEMBER,
        self::TRIGGER_VOUCHER_REDEEMED,
        self::TRIGGER_REWARD_REDEEMED,
    ];

    /** @var list<string> */
    public const ACTION_TYPES = [
        self::ACTION_ISSUE_VOUCHER,
        self::ACTION_SEND_NOTIFICATION,
        self::ACTION_ASSIGN_CAMPAIGN,
    ];

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'description',
        'trigger_type',
        'condition_json',
        'action_type',
        'action_config_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'condition_json' => 'array',
            'action_config_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LoyaltyAutomationLog::class, 'automation_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function conditionConfig(): array
    {
        return is_array($this->condition_json) ? $this->condition_json : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function actionConfig(): array
    {
        return is_array($this->action_config_json) ? $this->action_config_json : [];
    }
}
