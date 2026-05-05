<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->full_name,
            'userId' => $this->user_id,
            'tenantId' => $this->tenant_id,
            'employeeNo' => $this->employee_no,
            'fullName' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'position' => $this->position,
            'outlet' => $this->outlet,
            'salaryType' => $this->salary_type,
            'baseSalary' => (float) $this->base_salary,
            'overtimeRate' => (float) $this->overtime_rate,
            'joinDate' => $this->hire_date?->toDateString(),
            'hireDate' => $this->hire_date?->toDateString(),
            'terminationDate' => $this->termination_date?->toDateString(),
            'status' => $this->status,
        ];
    }
}
