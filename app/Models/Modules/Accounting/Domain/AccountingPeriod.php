<?php

namespace App\Models\Modules\Accounting\Domain;

use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by',
        'closed_by_user_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
    ];
}
