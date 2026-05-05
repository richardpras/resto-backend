<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Employee;
use Symfony\Component\HttpFoundation\Response;

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
            'full_name' => $payload['name'] ?? $payload['fullName'],
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'position' => $payload['position'],
            'outlet' => $payload['outlet'] ?? null,
            'salary_type' => $payload['salaryType'] ?? 'monthly',
            'base_salary' => $payload['baseSalary'],
            'overtime_rate' => $payload['overtimeRate'] ?? 0,
            'hire_date' => $payload['joinDate'] ?? $payload['hireDate'] ?? null,
            'termination_date' => $payload['terminationDate'] ?? null,
            'status' => $payload['status'] ?? 'active',
            'created_by' => request()->user()?->id,
            'updated_by' => request()->user()?->id,
        ]);
    }

    public function find(int $employeeId): Employee
    {
        $employee = Employee::query()->find($employeeId);
        abort_if($employee === null, Response::HTTP_NOT_FOUND, 'Employee not found.');

        return $employee;
    }

    public function update(int $employeeId, array $payload): Employee
    {
        $employee = $this->find($employeeId);
        $employee->fill([
            'user_id' => $payload['userId'] ?? null,
            'tenant_id' => $payload['tenantId'] ?? null,
            'employee_no' => $payload['employeeNo'],
            'full_name' => $payload['name'] ?? $payload['fullName'],
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'position' => $payload['position'],
            'outlet' => $payload['outlet'] ?? null,
            'salary_type' => $payload['salaryType'] ?? 'monthly',
            'base_salary' => $payload['baseSalary'],
            'overtime_rate' => $payload['overtimeRate'] ?? 0,
            'hire_date' => $payload['joinDate'] ?? $payload['hireDate'] ?? null,
            'termination_date' => $payload['terminationDate'] ?? null,
            'status' => $payload['status'] ?? 'active',
            'updated_by' => request()->user()?->id,
        ])->save();

        return $employee->refresh();
    }

    public function delete(int $employeeId): void
    {
        $employee = Employee::query()->find($employeeId);
        abort_if($employee === null, Response::HTTP_NOT_FOUND, 'Employee not found.');
        abort_if(
            $employee->payrolls()->exists() || $employee->attendances()->exists(),
            Response::HTTP_CONFLICT,
            'Employee cannot be deleted while payroll or attendance records exist.',
        );
        $employee->delete();
    }
}
