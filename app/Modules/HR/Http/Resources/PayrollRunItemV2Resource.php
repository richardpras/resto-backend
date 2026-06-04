<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayrollRunItemV2 */
class PayrollRunItemV2Resource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        return [
            'id' => (int) $this->id,
            'payrollRunId' => (int) $this->payroll_run_id,
            'employeeId' => (int) $this->employee_id,
            'basicSalary' => (float) $this->basic_salary,
            'attendanceDays' => (int) $this->attendance_days,
            'absentDays' => (int) $this->absent_days,
            'leaveDays' => (float) $this->leave_days,
            'unpaidLeaveDays' => (float) $this->unpaid_leave_days,
            'overtimeHours' => (float) $this->overtime_hours,
            'overtimePay' => (float) $this->overtime_pay,
            'unpaidLeaveDeduction' => (float) $this->unpaid_leave_deduction,
            'attendanceDeduction' => (float) $this->attendance_deduction,
            'loanDeduction' => (float) $this->loan_deduction,
            'remainingLoanBalance' => (float) $this->remaining_loan_balance,
            'cashAdvanceDeduction' => (float) $this->cash_advance_deduction,
            'remainingCashAdvanceBalance' => (float) $this->remaining_cash_advance_balance,
            'adjustmentEarning' => (float) $this->adjustment_earning,
            'adjustmentDeduction' => (float) $this->adjustment_deduction,
            'bpjsKesehatanEmployee' => (float) $this->bpjs_kesehatan_employee,
            'bpjsKesehatanCompany' => (float) $this->bpjs_kesehatan_company,
            'bpjsJhtEmployee' => (float) $this->bpjs_jht_employee,
            'bpjsJhtCompany' => (float) $this->bpjs_jht_company,
            'bpjsJpEmployee' => (float) $this->bpjs_jp_employee,
            'bpjsJpCompany' => (float) $this->bpjs_jp_company,
            'bpjsJkkCompany' => (float) $this->bpjs_jkk_company,
            'bpjsJkmCompany' => (float) $this->bpjs_jkm_company,
            'grossSalary' => (float) $this->gross_salary,
            'totalDeductions' => (float) $this->total_deductions,
            'netSalary' => (float) $this->net_salary,
            'calculationJson' => $this->calculation_json,
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
        ];
    }
}
