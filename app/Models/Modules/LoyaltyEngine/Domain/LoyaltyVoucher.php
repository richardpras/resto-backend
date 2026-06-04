<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyVoucher extends Model
{
    public const TYPE_MANUAL = 'manual';

    public const TYPE_CAMPAIGN = 'campaign';

    public const TYPE_REWARD = 'reward';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_MANUAL,
        self::TYPE_CAMPAIGN,
        self::TYPE_REWARD,
    ];

    public const VALUE_PERCENTAGE = 'percentage';

    public const VALUE_FIXED_AMOUNT = 'fixed_amount';

    public const VALUE_FREE_ITEM = 'free_item';

    /** @var list<string> */
    public const VALUE_TYPES = [
        self::VALUE_PERCENTAGE,
        self::VALUE_FIXED_AMOUNT,
        self::VALUE_FREE_ITEM,
    ];

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'description',
        'voucher_type',
        'value_type',
        'value',
        'minimum_spend',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'value' => 'decimal:2',
            'minimum_spend' => 'decimal:2',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function memberVouchers(): HasMany
    {
        return $this->hasMany(MemberVoucher::class, 'voucher_id');
    }
}
