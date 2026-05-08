<?php

namespace App\Models\Modules\Print\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintReprintAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'outlet_id',
        'user_id',
        'print_job_id',
        'receipt_render_history_id',
        'action',
        'reason',
        'meta',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<User, PrintReprintAudit> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<PrintJob, PrintReprintAudit> */
    public function printJob(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'print_job_id');
    }

    /** @return BelongsTo<ReceiptRenderHistory, PrintReprintAudit> */
    public function renderHistory(): BelongsTo
    {
        return $this->belongsTo(ReceiptRenderHistory::class, 'receipt_render_history_id');
    }
}
