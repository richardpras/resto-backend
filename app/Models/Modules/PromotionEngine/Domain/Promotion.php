<?php

namespace App\Models\Modules\PromotionEngine\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promotion extends Model
{
    public const TYPE_PERCENTAGE_ORDER = 'percentage_order';

    public const TYPE_PERCENTAGE_ITEMS = 'percentage_items';

    public const TYPE_FIXED_AMOUNT = 'fixed_amount';

    public const TYPE_BUY_X_GET_Y = 'buy_x_get_y';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_PERCENTAGE_ORDER,
        self::TYPE_PERCENTAGE_ITEMS,
        self::TYPE_FIXED_AMOUNT,
        self::TYPE_BUY_X_GET_Y,
    ];

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'description',
        'type',
        'config',
        'conditions',
        'priority',
        'is_combinable',
        'exclusive',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'config' => 'array',
            'conditions' => 'array',
            'priority' => 'integer',
            'is_combinable' => 'boolean',
            'exclusive' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
