<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Member;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberVoucher extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_REDEEMED = 'redeemed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ISSUED,
        self::STATUS_CLAIMED,
        self::STATUS_REDEEMED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    public const CAMPAIGN_NOTE_PREFIX = 'campaign:';

    protected $fillable = [
        'outlet_id',
        'member_id',
        'voucher_id',
        'voucher_code',
        'status',
        'issued_at',
        'claimed_at',
        'redeemed_at',
        'expired_at',
        'cancelled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'member_id' => 'integer',
            'voucher_id' => 'integer',
            'issued_at' => 'datetime',
            'claimed_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(LoyaltyVoucher::class, 'voucher_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public static function campaignNote(int $campaignId): string
    {
        return self::CAMPAIGN_NOTE_PREFIX.$campaignId;
    }
}
