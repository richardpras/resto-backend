<?php

namespace App\Models\Modules\Orders\Domain;

use App\Models\Modules\PromotionEngine\Domain\Promotion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPromotion extends Model
{
    protected $fillable = [
        'order_id',
        'promotion_id',
        'promotion_code',
        'promotion_name',
        'discount_type',
        'discount_value',
        'discount_amount',
        'applied_items',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'promotion_id' => 'integer',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'applied_items' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
