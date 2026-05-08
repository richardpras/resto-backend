<?php

namespace App\Models\Modules\Print\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJobEvent extends Model
{
    protected $fillable = [
        'print_job_id',
        'outlet_id',
        'event_type',
        'status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'print_job_id');
    }
}
