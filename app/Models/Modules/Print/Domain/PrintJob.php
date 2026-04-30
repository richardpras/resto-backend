<?php

namespace App\Models\Modules\Print\Domain;

use Illuminate\Database\Eloquent\Model;

class PrintJob extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'type',
        'printer_id',
        'source_type',
        'source_id',
        'content',
        'status',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'content' => 'array',
    ];
}
