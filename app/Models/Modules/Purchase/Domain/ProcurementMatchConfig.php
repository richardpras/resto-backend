<?php

namespace App\Models\Modules\Purchase\Domain;

use Illuminate\Database\Eloquent\Model;

class ProcurementMatchConfig extends Model
{
    protected $fillable = [
        'outlet_id',
        'quantity_tolerance_percent',
        'price_tolerance_percent',
        'amount_tolerance_percent',
        'auto_approve_within_tolerance',
        'is_active',
    ];

    protected $casts = [
        'quantity_tolerance_percent' => 'decimal:4',
        'price_tolerance_percent' => 'decimal:4',
        'amount_tolerance_percent' => 'decimal:4',
        'auto_approve_within_tolerance' => 'boolean',
        'is_active' => 'boolean',
    ];
}
