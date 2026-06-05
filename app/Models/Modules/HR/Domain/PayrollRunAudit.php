<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunAudit extends Model
{
    public const ACTION_CALCULATED = 'calculated';

    public const ACTION_APPROVED = 'approved';

    public const ACTION_FINALIZED = 'finalized';

    public const ACTION_PAYMENT_STARTED = 'payment_started';

    public const ACTION_PAYMENT_COMPLETED = 'payment_completed';

    public const ACTION_CLOSED = 'closed';

    public const ACTION_REOPENED = 'reopened';

    public const ACTION_POSTING_CREATED = 'posting_created';

    public const ACTION_POSTING_REVERSED = 'posting_reversed';

    protected $table = 'payroll_run_audits';

    protected $fillable = [
        'payroll_run_id',
        'action',
        'performed_by',
        'notes',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRunV2::class, 'payroll_run_id');
    }

    public function performedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
