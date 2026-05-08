<?php

namespace App\Models\Modules\Print\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintJob extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'type',
        'printer_id',
        'printer_profile_id',
        'printer_route_id',
        'source_type',
        'source_id',
        'idempotency_key',
        'dedupe_key',
        'content',
        'printable_snapshot',
        'route_snapshot',
        'status',
        'queued_at',
        'locked_at',
        'locked_by',
        'attempts',
        'last_attempt_at',
        'next_retry_at',
        'max_attempts',
        'retryable',
        'failed_at',
        'recovery_state',
        'recovered_from_job_id',
        'last_error',
        'failure_context',
        'processed_at',
        'receipt_render_history_id',
    ];

    protected $casts = [
        'content' => 'array',
        'printable_snapshot' => 'array',
        'route_snapshot' => 'array',
        'queued_at' => 'datetime',
        'locked_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'failed_at' => 'datetime',
        'processed_at' => 'datetime',
        'retryable' => 'boolean',
        'failure_context' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PrinterProfile::class, 'printer_profile_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(PrinterRoute::class, 'printer_route_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PrintJobEvent::class, 'print_job_id');
    }

    public function receiptRenderHistory(): BelongsTo
    {
        return $this->belongsTo(ReceiptRenderHistory::class, 'receipt_render_history_id');
    }
}
