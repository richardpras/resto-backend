<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'base_salary',
        'attendance_adjustment',
        'overtime_pay',
        'allowances',
        'deductions',
        'loan_deduction',
        'taxable_income',
        'pph21',
        'net_salary',
        'working_days',
        'present_days',
        'overtime_hours',
        'payment_status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'attendance_adjustment' => 'decimal:2',
        'overtime_pay' => 'decimal:2',
        'allowances' => 'decimal:2',
        'deductions' => 'decimal:2',
        'loan_deduction' => 'decimal:2',
        'taxable_income' => 'decimal:2',
        'pph21' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
