<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPayslip extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'outlet_id',
        'payroll_run_id',
        'payroll_run_item_id',
        'employee_id',
        'payroll_period_id',
        'payslip_no',
        'gross_salary',
        'total_deductions',
        'net_salary',
        'breakdown_json',
        'pdf_path',
        'status',
        'published_at',
    ];

    protected $casts = [
        'gross_salary' => 'float',
        'total_deductions' => 'float',
        'net_salary' => 'float',
        'breakdown_json' => 'array',
        'published_at' => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRunV2::class, 'payroll_run_id');
    }

    public function payrollRunItem(): BelongsTo
    {
        return $this->belongsTo(PayrollRunItemV2::class, 'payroll_run_item_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPreparationPeriod::class, 'payroll_period_id');
    }
}
