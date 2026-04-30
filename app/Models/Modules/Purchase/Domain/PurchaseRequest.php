<?php

namespace App\Models\Modules\Purchase\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseRequest extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'number',
        'status',
        'request_date',
        'notes',
    ];

    protected $casts = [
        'request_date' => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class);
    }
}
