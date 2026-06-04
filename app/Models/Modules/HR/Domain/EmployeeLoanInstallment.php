<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLoanInstallment extends Model
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_DEDUCTED = 'deducted';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'loan_id',
        'installment_no',
        'due_date',
        'amount',
        'status',
        'payroll_run_item_id',
    ];

    protected $casts = [
        'due_date' => 'date',
        'amount' => 'float',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class, 'loan_id');
    }

    public function payrollRunItem(): BelongsTo
    {
        return $this->belongsTo(PayrollRunItemV2::class, 'payroll_run_item_id');
    }
}
