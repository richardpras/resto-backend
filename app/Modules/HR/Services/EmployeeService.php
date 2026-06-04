<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

class EmployeeService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    public function listByTenant(?User $user, int $tenantId)
    {
        return $this->employeeMaster
            ->scopedEmployeeQuery($user)
            ->when($tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->latest('id')
            ->get()
            ->map(fn (Employee $employee) => $this->employeeMaster->syncStoredRow($employee));
    }

    public function create(?User $user, array $payload): Employee
    {
        $attributes = $this->employeeMaster->normalizeAttributes([
            'user_id' => $payload['userId'] ?? null,
            'tenant_id' => $payload['tenantId'] ?? null,
            'outlet_id' => $payload['outletId'] ?? $payload['outlet_id'] ?? null,
            'employee_no' => $payload['employeeNo'],
            'full_name' => $payload['name'] ?? $payload['fullName'],
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'position' => $payload['position'],
            'position_id' => $payload['positionId'] ?? $payload['position_id'] ?? null,
            'department_id' => $payload['departmentId'] ?? $payload['department_id'] ?? null,
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

        if (($attributes['outlet_id'] ?? null) === null) {
            $attributes['outlet_id'] = \App\Models\Modules\Settings\Domain\Outlet::query()->orderBy('id')->value('id');
            $attributes = $this->employeeMaster->normalizeAttributes($attributes);
        }

        if ($user !== null && isset($attributes['outlet_id'])) {
            $this->employeeMaster->assertEmployeeOutletAllowed(
                $user,
                (new Employee)->forceFill(['outlet_id' => $attributes['outlet_id']]),
            );
        }

        $employee = Employee::query()->create($attributes);

        return $this->employeeMaster->syncStoredRow($employee);
    }

    public function find(?User $user, int $employeeId): Employee
    {
        return $this->employeeMaster->findAccessible($user, $employeeId);
    }

    public function update(?User $user, int $employeeId, array $payload): Employee
    {
        $employee = $this->find($user, $employeeId);

        $attributes = $this->employeeMaster->normalizeAttributes([
            'user_id' => $payload['userId'] ?? $employee->user_id,
            'tenant_id' => $payload['tenantId'] ?? $employee->tenant_id,
            'employee_no' => $payload['employeeNo'] ?? $employee->employee_no,
            'full_name' => $payload['name'] ?? $payload['fullName'] ?? $employee->full_name,
            'email' => $payload['email'] ?? $employee->email,
            'phone' => $payload['phone'] ?? $employee->phone,
            'position' => $payload['position'] ?? $employee->position,
            'position_id' => $payload['positionId'] ?? $payload['position_id'] ?? $employee->position_id,
            'department_id' => $payload['departmentId'] ?? $payload['department_id'] ?? $employee->department_id,
            'outlet' => $payload['outlet'] ?? $employee->outlet,
            'outlet_id' => $payload['outletId'] ?? $payload['outlet_id'] ?? $employee->outlet_id,
            'salary_type' => $payload['salaryType'] ?? $employee->salary_type,
            'base_salary' => $payload['baseSalary'] ?? $employee->base_salary,
            'overtime_rate' => $payload['overtimeRate'] ?? $employee->overtime_rate,
            'hire_date' => $payload['joinDate'] ?? $payload['hireDate'] ?? $employee->hire_date,
            'termination_date' => $payload['terminationDate'] ?? $employee->termination_date,
            'status' => $payload['status'] ?? $employee->status,
            'updated_by' => request()->user()?->id,
        ]);

        if ($user !== null) {
            $this->employeeMaster->assertEmployeeOutletAllowed(
                $user,
                (new Employee)->forceFill(['outlet_id' => $attributes['outlet_id'] ?? $employee->outlet_id]),
            );
        }

        $employee->fill($attributes)->save();

        return $this->employeeMaster->syncStoredRow($employee);
    }

    public function delete(?User $user, int $employeeId): void
    {
        $employee = $this->find($user, $employeeId);
        abort_if(
            $employee->payrolls()->exists() || $employee->attendances()->exists(),
            Response::HTTP_CONFLICT,
            'Employee cannot be deleted while payroll or attendance records exist.',
        );
        $employee->delete();
    }
}
