<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GoodsReceivingNote extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'purchase_order_id',
        'destination_warehouse_id',
        'warehouse_id',
        'number',
        'status',
        'received_date',
        'notes',
        'supplier_delivery_no',
        'supplier_delivery_date',
        'vehicle_no',
        'driver_name',
        'received_by',
        'received_at',
        'posted_at',
        'cancelled_at',
        'posted_by',
        'cancelled_by',
    ];

    protected $casts = [
        'received_date' => 'date',
        'supplier_delivery_date' => 'date',
        'received_at' => 'datetime',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceivingNoteItem::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(PurchaseInvoice::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class);
    }
}
