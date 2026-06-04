<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Member;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyNotification extends Model
{
    public const EVENT_POINT_EARNED = 'POINT_EARNED';

    public const EVENT_POINT_REDEEMED = 'POINT_REDEEMED';

    public const EVENT_POINT_EXPIRED = 'POINT_EXPIRED';

    public const EVENT_REWARD_REDEEMED = 'REWARD_REDEEMED';

    public const EVENT_VOUCHER_ISSUED = 'VOUCHER_ISSUED';

    public const EVENT_VOUCHER_REDEEMED = 'VOUCHER_REDEEMED';

    public const EVENT_TIER_UPGRADED = 'TIER_UPGRADED';

    public const EVENT_CAMPAIGN_ACTIVATED = 'CAMPAIGN_ACTIVATED';

    public const CHANNEL_IN_APP = 'in_app';

    public const CHANNEL_EMAIL = 'email';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_READ = 'read';

    protected $fillable = [
        'outlet_id',
        'member_id',
        'event_type',
        'channel',
        'title',
        'content',
        'status',
        'payload_json',
        'sent_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
