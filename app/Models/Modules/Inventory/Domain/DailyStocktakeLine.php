<?php

namespace App\Models\Modules\Inventory\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyStocktakeLine extends Model
{
    protected $fillable = [
        'session_id',
        'ingredient_id',
        'previous_closing_qty',
        'opening_qty',
        'closing_qty',
        'purchases_qty',
        'theoretical_usage_qty',
        'overnight_variance_qty',
        'operational_variance_qty',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'previous_closing_qty' => 'float',
            'opening_qty' => 'float',
            'closing_qty' => 'float',
            'purchases_qty' => 'float',
            'theoretical_usage_qty' => 'float',
            'overnight_variance_qty' => 'float',
            'operational_variance_qty' => 'float',
            'unit_cost' => 'float',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DailyStocktakeSession::class, 'session_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id');
    }
}
