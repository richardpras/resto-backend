<?php

namespace App\Models\Modules\Menu\Domain;

use App\Models\Modules\Inventory\Domain\Ingredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuRecipe extends Model
{
    protected $fillable = [
        'menu_item_id',
        'inventory_item_id',
        'quantity',
    ];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'inventory_item_id');
    }
}
