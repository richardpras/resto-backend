<?php

namespace App\Models\Modules\Purchase\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoice extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'purchase_order_id',
        'goods_receiving_note_id',
        'number',
        'invoice_date',
        'total',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'total' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceivingNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivingNote::class);
    }
}
