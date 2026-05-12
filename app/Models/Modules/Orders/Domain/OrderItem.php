<?php

namespace App\Models\Modules\Orders\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'item_id',
        'name',
        'emoji',
        'qty',
        'price',
        'line_total',
        'notes',
        'recovery_status',
        'recovery_reason',
        'recovery_approved_by_user_id',
        'recovery_approved_at',
        'replaced_by_order_item_id',
    ];

    protected $casts = [
        'recovery_approved_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(OrderPaymentAllocation::class);
    }
}
