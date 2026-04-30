<?php

namespace App\Models\Modules\Orders\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPaymentAllocation extends Model
{
    protected $fillable = [
        'payment_id',
        'order_item_id',
        'qty',
        'amount',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
