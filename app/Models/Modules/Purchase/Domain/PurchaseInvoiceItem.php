<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Modules\Inventory\Domain\Ingredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceItem extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'goods_receiving_note_item_id',
        'ingredient_id',
        'received_qty',
        'invoiced_qty',
        'qty',
        'unit_cost',
        'unit_price',
        'line_subtotal',
        'line_tax_amount',
        'line_total',
    ];

    protected $casts = [
        'received_qty' => 'decimal:2',
        'invoiced_qty' => 'decimal:2',
        'qty' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'line_tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function goodsReceivingNoteItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivingNoteItem::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
