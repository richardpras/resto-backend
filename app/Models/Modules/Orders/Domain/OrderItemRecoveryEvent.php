<?php

namespace App\Models\Modules\Orders\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemRecoveryEvent extends Model
{
    protected $table = 'order_item_recovery_events';

    protected $fillable = [
        'outlet_id',
        'order_id',
        'order_item_id',
        'event_code',
        'recovery_status',
        'reason',
        'payload',
        'actor_user_id',
        'manager_user_id',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
