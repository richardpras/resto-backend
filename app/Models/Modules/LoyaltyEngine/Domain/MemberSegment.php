<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberSegment extends Model
{
    public const TYPE_VIP_SPENDER = 'vip_spender';

    public const TYPE_FREQUENT_VISITOR = 'frequent_visitor';

    public const TYPE_BIRTHDAY_MONTH = 'birthday_month';

    public const TYPE_INACTIVE_MEMBER = 'inactive_member';

    public const TYPE_NEVER_REDEEMED = 'never_redeemed';

    public const TYPE_EXPIRING_SOON = 'expiring_soon';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_VIP_SPENDER,
        self::TYPE_FREQUENT_VISITOR,
        self::TYPE_BIRTHDAY_MONTH,
        self::TYPE_INACTIVE_MEMBER,
        self::TYPE_NEVER_REDEEMED,
        self::TYPE_EXPIRING_SOON,
    ];

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'description',
        'segment_type',
        'config_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'config_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return is_array($this->config_json) ? $this->config_json : [];
    }
}
