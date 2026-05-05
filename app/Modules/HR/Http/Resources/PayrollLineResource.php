<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'employeeId' => (int) $this->employee_id,
            'baseSalary' => (float) $this->base_salary,
            'attendanceAdjustment' => (float) $this->attendance_adjustment,
            'overtimePay' => (float) $this->overtime_pay,
            'allowances' => (float) $this->allowances,
            'deductions' => (float) $this->deductions,
            'loanDeduction' => (float) $this->loan_deduction,
            'taxableIncome' => (float) $this->taxable_income,
            'pph21' => (float) $this->pph21,
            'netSalary' => (float) $this->net_salary,
            'workingDays' => (int) $this->working_days,
            'presentDays' => (int) $this->present_days,
            'overtimeHours' => (float) $this->overtime_hours,
            'paymentStatus' => (string) ($this->payment_status ?? 'unlocked'),
        ];
    }
}
