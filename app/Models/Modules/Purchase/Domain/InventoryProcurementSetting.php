<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryProcurementSetting extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'preferred_supplier_id',
        'minimum_order_qty',
        'reorder_qty',
        'lead_time_days',
        'last_purchase_price',
        'is_active',
    ];

    protected $casts = [
        'minimum_order_qty' => 'decimal:2',
        'reorder_qty' => 'decimal:2',
        'last_purchase_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'inventory_item_id');
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }
}
