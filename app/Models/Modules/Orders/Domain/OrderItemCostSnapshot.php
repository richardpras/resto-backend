<?php

namespace App\Models\Modules\Orders\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemCostSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_item_id',
        'menu_item_id',
        'recipe_version_id',
        'outlet_id',
        'cost_per_unit',
        'total_cost',
        'average_cost_version',
        'costing_method_snapshot',
        'created_at',
    ];

    protected $casts = [
        'cost_per_unit' => 'float',
        'total_cost' => 'float',
        'created_at' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
