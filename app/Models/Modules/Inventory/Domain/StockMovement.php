<?php

namespace App\Models\Modules\Inventory\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'outlet_id',
        'type',
        'quantity',
        'source_type',
        'source_id',
        'unit_cost',
        'total_cost',
        'ledger_payload',
    ];

    protected $casts = [
        'outlet_id' => 'integer',
        'unit_cost' => 'float',
        'total_cost' => 'float',
        'ledger_payload' => 'array',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'inventory_item_id');
    }
}
