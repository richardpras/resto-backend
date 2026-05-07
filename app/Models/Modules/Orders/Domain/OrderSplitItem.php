<?php

namespace App\Models\Modules\Orders\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSplitItem extends Model
{
    protected $fillable = [
        'order_split_id',
        'order_item_id',
        'qty',
        'amount',
    ];

    public function split(): BelongsTo
    {
        return $this->belongsTo(OrderSplit::class, 'order_split_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
