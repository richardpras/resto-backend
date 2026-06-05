<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayment extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'supplier_id',
        'payment_no',
        'payment_date',
        'payment_method',
        'reference_no',
        'notes',
        'amount',
        'allocated_amount',
        'unallocated_amount',
        'status',
        'approved_at',
        'approved_by',
        'posted_at',
        'posted_by',
        'voided_at',
        'voided_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
        'unallocated_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }
}
