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
            'userId' => $this->user_id,
            'tenantId' => $this->tenant_id,
            'employeeNo' => $this->employee_no,
            'fullName' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'position' => $this->position,
            'baseSalary' => (float) $this->base_salary,
            'hireDate' => $this->hire_date?->toDateString(),
            'status' => $this->status,
        ];
    }
}
