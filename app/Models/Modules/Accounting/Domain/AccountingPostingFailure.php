<?php

namespace App\Models\Modules\Accounting\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPostingFailure extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    public const ERROR_MISSING_MAPPING = 'missing_mapping';

    public const ERROR_UNBALANCED = 'unbalanced_journal';

    public const ERROR_PERIOD_LOCKED = 'period_locked';

    public const ERROR_DUPLICATE = 'duplicate_posting';

    public const ERROR_POSTING = 'posting_error';

    protected $fillable = [
        'source_type',
        'source_id',
        'outlet_id',
        'error_code',
        'error_message',
        'payload_json',
        'status',
        'journal_id',
        'resolved_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }
}
