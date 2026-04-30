<?php

namespace App\Models\Modules\Purchase\Domain;

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
        'number',
        'received_date',
        'notes',
    ];

    protected $casts = [
        'received_date' => 'date',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceivingNoteItem::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(PurchaseInvoice::class);
    }
}
