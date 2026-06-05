<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementMatchResult extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'goods_receipt_id',
        'invoice_id',
        'match_status',
        'qty_difference',
        'price_difference',
        'amount_difference',
        'matched_at',
        'matched_by',
        'notes',
    ];

    protected $casts = [
        'qty_difference' => 'decimal:4',
        'price_difference' => 'decimal:4',
        'amount_difference' => 'decimal:4',
        'matched_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceivingNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivingNote::class, 'goods_receipt_id');
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'invoice_id');
    }

    public function matchedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
