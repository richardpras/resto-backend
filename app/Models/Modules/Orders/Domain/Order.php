<?php

namespace App\Models\Modules\Orders\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'code',
        'source',
        'order_type',
        'status',
        'payment_status',
        'subtotal',
        'tax',
        'total',
        'discount_amount',
        'paid_total',
        'balance_due',
        'customer_name',
        'customer_phone',
        'table_number',
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
}
