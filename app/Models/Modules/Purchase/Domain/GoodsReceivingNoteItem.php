<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Modules\Inventory\Domain\Ingredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceivingNoteItem extends Model
{
    protected $fillable = [
        'goods_receiving_note_id',
        'purchase_order_item_id',
        'ingredient_id',
        'received_qty',
    ];

    protected $casts = [
        'received_qty' => 'decimal:2',
    ];

    public function goodsReceivingNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivingNote::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
