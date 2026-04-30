<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Modules\Inventory\Domain\Ingredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'ingredient_id',
        'ordered_qty',
        'received_qty',
        'unit_price',
    ];

    protected $casts = [
        'ordered_qty' => 'decimal:2',
        'received_qty' => 'decimal:2',
        'unit_price' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
