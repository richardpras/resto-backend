<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeSalaryProfile */
class EmployeeSalaryProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        return [
            'id' => (int) $this->id,
            'employeeId' => (int) $this->employee_id,
            'basicSalary' => (float) $this->basic_salary,
            'defaultAllowance' => (float) $this->default_allowance,
            'defaultDeduction' => (float) $this->default_deduction,
            'overtimeRateType' => $this->overtime_rate_type,
            'overtimeRateValue' => (float) $this->overtime_rate_value,
            'unpaidLeaveDeductionEnabled' => (bool) $this->unpaid_leave_deduction_enabled,
            'attendanceDeductionEnabled' => (bool) $this->attendance_deduction_enabled,
            'attendanceDeductionPerDay' => $this->attendance_deduction_per_day !== null
                ? (float) $this->attendance_deduction_per_day
                : null,
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
                'outletId' => (int) $employee->outlet_id,
            ] : null,
        ];
    }
}
