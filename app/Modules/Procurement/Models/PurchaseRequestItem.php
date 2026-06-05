<?php

namespace App\Modules\Procurement\Models;

use App\Models\Modules\Inventory\Domain\Ingredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    protected $table = 'purchase_request_items_v2';

    protected $fillable = [
        'purchase_request_id',
        'inventory_item_id',
        'quantity',
        'unit',
        'estimated_cost',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'float',
        'estimated_cost' => 'float',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'inventory_item_id');
    }
}
