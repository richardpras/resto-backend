<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunItemV2 extends Model
{
    protected $table = 'payroll_run_items_v2';

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'basic_salary',
        'attendance_days',
        'absent_days',
        'leave_days',
        'unpaid_leave_days',
        'overtime_hours',
        'overtime_pay',
        'unpaid_leave_deduction',
        'attendance_deduction',
        'loan_deduction',
        'remaining_loan_balance',
        'cash_advance_deduction',
        'remaining_cash_advance_balance',
        'adjustment_earning',
        'adjustment_deduction',
        'bpjs_kesehatan_employee',
        'bpjs_kesehatan_company',
        'bpjs_jht_employee',
        'bpjs_jht_company',
        'bpjs_jp_employee',
        'bpjs_jp_company',
        'bpjs_jkk_company',
        'bpjs_jkm_company',
        'taxable_income',
        'annual_taxable_income',
        'pph21_amount',
        'reimbursement_earning',
        'remaining_reimbursement',
        'gross_salary',
        'total_deductions',
        'net_salary',
        'calculation_json',
    ];

    protected $casts = [
        'basic_salary' => 'float',
        'leave_days' => 'float',
        'unpaid_leave_days' => 'float',
        'overtime_hours' => 'float',
        'overtime_pay' => 'float',
        'unpaid_leave_deduction' => 'float',
        'attendance_deduction' => 'float',
        'loan_deduction' => 'float',
        'remaining_loan_balance' => 'float',
        'cash_advance_deduction' => 'float',
        'remaining_cash_advance_balance' => 'float',
        'adjustment_earning' => 'float',
        'adjustment_deduction' => 'float',
        'bpjs_kesehatan_employee' => 'float',
        'bpjs_kesehatan_company' => 'float',
        'bpjs_jht_employee' => 'float',
        'bpjs_jht_company' => 'float',
        'bpjs_jp_employee' => 'float',
        'bpjs_jp_company' => 'float',
        'bpjs_jkk_company' => 'float',
        'bpjs_jkm_company' => 'float',
        'taxable_income' => 'float',
        'annual_taxable_income' => 'float',
        'pph21_amount' => 'float',
        'reimbursement_earning' => 'float',
        'remaining_reimbursement' => 'float',
        'gross_salary' => 'float',
        'total_deductions' => 'float',
        'net_salary' => 'float',
        'calculation_json' => 'array',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRunV2::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
