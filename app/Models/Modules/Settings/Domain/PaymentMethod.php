<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'type',
        'integration',
        'fee',
        'status',
    ];

    protected $casts = [
        'fee' => 'decimal:4',
    ];
}
