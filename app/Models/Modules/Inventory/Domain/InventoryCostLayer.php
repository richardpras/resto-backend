<?php

namespace App\Models\Modules\Inventory\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCostLayer extends Model
{
    protected $fillable = [
        'ingredient_id',
        'outlet_id',
        'source_movement_id',
        'grn_id',
        'qty_received',
        'qty_remaining',
        'unit_cost',
        'received_at',
    ];

    protected $casts = [
        'qty_received' => 'float',
        'qty_remaining' => 'float',
        'unit_cost' => 'float',
        'received_at' => 'datetime',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
