<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRunV2 extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_PROCESSING_PAYMENT = 'processing_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_CLOSED = 'closed';

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PROCESSING = 'processing';

    public const PAYMENT_PAID = 'paid';

    protected $table = 'payroll_runs_v2';

    protected $fillable = [
        'outlet_id',
        'payroll_preparation_period_id',
        'status',
        'payment_status',
        'approved_by',
        'approved_at',
        'finalized_by',
        'finalized_at',
        'paid_at',
        'closed_at',
        'closed_by',
        'closed_notes',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'finalized_at' => 'datetime',
        'paid_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function assertNotClosed(): void
    {
        if ($this->isClosed()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Payroll run already closed.'],
            ]);
        }
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(PayrollPayslip::class, 'payroll_run_id');
    }

    public function preparationPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPreparationPeriod::class, 'payroll_preparation_period_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function finalizedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollRunItemV2::class, 'payroll_run_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(PayrollRunAudit::class, 'payroll_run_id');
    }

    public function posting(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PayrollPosting::class, 'payroll_run_id');
    }
}
