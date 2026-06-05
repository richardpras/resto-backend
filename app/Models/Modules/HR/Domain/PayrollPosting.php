<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPosting extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'payroll_run_id',
        'journal_entry_id',
        'posting_status',
        'posted_at',
        'reversed_at',
        'reversed_by',
        'notes',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRunV2::class, 'payroll_run_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_entry_id');
    }

    public function reversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
