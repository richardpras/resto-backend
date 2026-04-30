<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Employee;

class EmployeeService
{
    public function listByTenant(int $tenantId)
    {
        return Employee::query()
            ->when($tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->latest('id')
            ->get();
    }

    public function create(array $payload): Employee
    {
        return Employee::query()->create([
            'user_id' => $payload['userId'] ?? null,
            'tenant_id' => $payload['tenantId'] ?? null,
            'employee_no' => $payload['employeeNo'],
            'full_name' => $payload['fullName'],
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'position' => $payload['position'],
            'base_salary' => $payload['baseSalary'],
            'hire_date' => $payload['hireDate'] ?? null,
            'status' => $payload['status'] ?? 'active',
        ]);
    }
}
