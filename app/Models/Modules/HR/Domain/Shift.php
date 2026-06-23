<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'start_time',
        'end_time',
        'late_tolerance_minutes',
        'overtime_after_minutes',
        'active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
