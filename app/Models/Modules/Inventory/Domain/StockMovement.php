<?php

namespace App\Models\Modules\Inventory\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'ingredient_id',
        'movement_type',
        'quantity',
        'source',
        'reference_no',
        'note',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
