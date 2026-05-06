<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Modules\Inventory\Domain\Ingredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'pr_item_id',
        'ingredient_id',
        'ordered_qty',
        'requested_qty',
        'is_from_pr',
        'received_qty',
        'unit_price',
    ];

    protected $casts = [
        'ordered_qty' => 'decimal:2',
        'requested_qty' => 'decimal:2',
        'is_from_pr' => 'boolean',
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

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'pr_item_id');
    }
}
