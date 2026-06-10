<?php

namespace App\Models\Modules\System\Domain;

use Illuminate\Database\Eloquent\Model;

class FailedJobSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'total_failures',
        'critical_failures',
        'resolved_failures',
        'health_status',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'total_failures' => 'integer',
            'critical_failures' => 'integer',
            'resolved_failures' => 'integer',
        ];
    }
}
