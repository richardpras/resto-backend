<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;

class Tax extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'type',
        'value',
        'apply_dine_in',
        'apply_takeaway',
        'inclusive',
        'status',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'apply_dine_in' => 'boolean',
        'apply_takeaway' => 'boolean',
        'inclusive' => 'boolean',
    ];
}
