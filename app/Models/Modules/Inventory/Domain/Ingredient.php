<?php

namespace App\Models\Modules\Inventory\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'import_code',
        'name',
        'type',
        'unit',
        'stock',
        'min',
        'price',
        'notes',
    ];

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'inventory_item_id');
    }

    /** Per-outlet ledger stock (authoritative for transactions). */
    public function inventoryStocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'ingredient_id');
    }
}
