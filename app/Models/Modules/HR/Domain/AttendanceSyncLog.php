<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;

class AttendanceSyncLog extends Model
{
    protected $fillable = [
        'source',
        'external_ref',
        'payload_hash',
        'received_at',
        'processed_at',
        'status',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
