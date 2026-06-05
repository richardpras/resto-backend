<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Supplier;
use App\Models\Warehouse;
use App\Modules\Procurement\Models\PurchaseRequest as PurchaseRequestV2;
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
        'destination_warehouse_id',
        'number',
        'status',
        'order_date',
        'supplier_name',
        'notes',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'cancelled_at',
        'cancelled_by',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestV2::class, 'purchase_request_id');
    }

    public function sourcePurchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestV2::class, 'source_pr_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
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
