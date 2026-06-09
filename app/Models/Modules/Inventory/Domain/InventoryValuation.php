<?php

namespace App\Models\Modules\Inventory\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryValuation extends Model
{
    protected $fillable = [
        'ingredient_id',
        'outlet_id',
        'stock_quantity',
        'inventory_value',
        'average_cost',
        'last_purchase_cost',
        'last_grn_id',
        'last_updated_at',
    ];

    protected $casts = [
        'stock_quantity' => 'float',
        'inventory_value' => 'float',
        'average_cost' => 'float',
        'last_purchase_cost' => 'float',
        'last_updated_at' => 'datetime',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
