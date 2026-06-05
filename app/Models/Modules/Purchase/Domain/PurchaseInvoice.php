<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseInvoice extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'purchase_order_id',
        'goods_receiving_note_id',
        'supplier_id',
        'number',
        'supplier_invoice_no',
        'invoice_date',
        'due_date',
        'subtotal',
        'tax_amount',
        'tax_percentage',
        'discount_amount',
        'total_amount',
        'total',
        'tax',
        'paid_amount',
        'outstanding_amount',
        'status',
        'notes',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'voided_at',
        'voided_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'tax' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceivingNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivingNote::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseInvoicePayment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function latestMatchResult(): HasOne
    {
        return $this->hasOne(ProcurementMatchResult::class, 'invoice_id')->latestOfMany();
    }

    public function matchResults(): HasMany
    {
        return $this->hasMany(ProcurementMatchResult::class, 'invoice_id');
    }
}
