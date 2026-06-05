<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\EmployeeUser;

class EmployeeProfileService
{
    /**
     * @return array<string, mixed>
     */
    public function profileForEmployeeUser(EmployeeUser $user): array
    {
        $employee = Employee::query()
            ->with(['positionRelation', 'department', 'outletRelation'])
            ->find((int) $user->employee_id);

        abort_if($employee === null, 404, 'Employee record not found.');

        $todayRoster = EmployeeRoster::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->whereDate('roster_date', now()->toDateString())
            ->where('status', EmployeeRoster::STATUS_PUBLISHED)
            ->orderBy('id')
            ->first();

        return [
            'employee' => [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'hireDate' => $employee->hire_date?->toDateString(),
            ],
            'position' => $employee->positionRelation ? [
                'id' => (int) $employee->positionRelation->id,
                'name' => $employee->positionRelation->name,
                'code' => $employee->positionRelation->code ?? null,
            ] : ($employee->position ? ['name' => $employee->position] : null),
            'department' => $employee->department ? [
                'id' => (int) $employee->department->id,
                'name' => $employee->department->name,
                'code' => $employee->department->code ?? null,
            ] : null,
            'outlet' => $employee->outletRelation ? [
                'id' => (int) $employee->outletRelation->id,
                'name' => $employee->outletRelation->name,
                'code' => $employee->outletRelation->code ?? null,
            ] : null,
            'shift' => $todayRoster?->shift ? [
                'id' => (int) $todayRoster->shift->id,
                'name' => $todayRoster->shift->name,
                'startTime' => $todayRoster->shift->start_time,
                'endTime' => $todayRoster->shift->end_time,
            ] : null,
            'employmentStatus' => [
                'status' => $employee->status,
                'hireDate' => $employee->hire_date?->toDateString(),
                'terminationDate' => $employee->termination_date?->toDateString(),
            ],
        ];
    }
}
