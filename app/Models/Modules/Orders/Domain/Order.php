<?php

namespace App\Models\Modules\Orders\Domain;

use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'pos_session_id',
        'code',
        'source',
        'source_type',
        'source_id',
        'source_code',
        'order_channel',
        'service_mode',
        'order_type',
        'status',
        'payment_status',
        'kitchen_status',
        'subtotal',
        'tax',
        'total',
        'discount_amount',
        'paid_total',
        'balance_due',
        'customer_name',
        'customer_phone',
        'member_id',
        'table_number',
        'table_id',
        'table_name',
        'split_bill',
        'confirmed_at',
        'stock_deducted_at',
        'is_posted',
    ];

    protected $casts = [
        'split_bill' => 'array',
        'confirmed_at' => 'datetime',
        'stock_deducted_at' => 'datetime',
        'is_posted' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(OrderSplit::class);
    }

    public function orderVoucher(): HasOne
    {
        return $this->hasOne(OrderVoucher::class);
    }

    public function orderPromotion(): HasOne
    {
        return $this->hasOne(OrderPromotion::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
