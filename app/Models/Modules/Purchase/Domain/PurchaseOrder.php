<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'purchase_request_id',
        'source_pr_id',
        'supplier_id',
        'number',
        'status',
        'order_date',
        'supplier_name',
        'notes',
    ];

    protected $casts = [
        'order_date' => 'date',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function sourcePurchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'source_pr_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceivingNotes(): HasMany
    {
        return $this->hasMany(GoodsReceivingNote::class);
    }

    public function grnItems(): HasManyThrough
    {
        return $this->hasManyThrough(
            GoodsReceivingNoteItem::class,
            GoodsReceivingNote::class,
            'purchase_order_id',
            'goods_receiving_note_id',
            'id',
            'id'
        );
    }
}
