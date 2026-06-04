<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EmployeeSalaryProfileService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, EmployeeSalaryProfile>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = EmployeeSalaryProfile::query()
            ->with('employee')
            ->orderBy('employee_id');

        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');

        if (! empty($filters['employeeId'])) {
            $query->where('employee_id', (int) $filters['employeeId']);
        }

        if (! empty($filters['outletId'])) {
            $query->whereHas('employee', fn ($q) => $q->where('outlet_id', (int) $filters['outletId']));
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $id): EmployeeSalaryProfile
    {
        $profile = EmployeeSalaryProfile::query()->with('employee')->find($id);
        abort_if($profile === null, Response::HTTP_NOT_FOUND, 'Salary profile not found.');

        $profile->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $profile->employee);

        return $profile;
    }

    public function create(?User $user, array $payload): EmployeeSalaryProfile
    {
        $employeeId = (int) ($payload['employeeId'] ?? 0);
        $employee = $this->employeeMaster->findAccessible($user, $employeeId);

        if (EmployeeSalaryProfile::query()->where('employee_id', $employeeId)->exists()) {
            throw ValidationException::withMessages([
                'employeeId' => ['This employee already has a salary profile.'],
            ]);
        }

        $this->validateDeductionSettings($payload);

        return EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => (float) ($payload['basicSalary'] ?? 0),
            'default_allowance' => (float) ($payload['defaultAllowance'] ?? 0),
            'default_deduction' => (float) ($payload['defaultDeduction'] ?? 0),
            'overtime_rate_type' => $payload['overtimeRateType'] ?? EmployeeSalaryProfile::OVERTIME_RATE_FIXED_HOURLY,
            'overtime_rate_value' => (float) ($payload['overtimeRateValue'] ?? 0),
            'unpaid_leave_deduction_enabled' => array_key_exists('unpaidLeaveDeductionEnabled', $payload)
                ? (bool) $payload['unpaidLeaveDeductionEnabled']
                : true,
            'attendance_deduction_enabled' => (bool) ($payload['attendanceDeductionEnabled'] ?? false),
            'attendance_deduction_per_day' => isset($payload['attendanceDeductionPerDay'])
                ? (float) $payload['attendanceDeductionPerDay']
                : null,
        ])->load('employee');
    }

    public function update(?User $user, int $id, array $payload): EmployeeSalaryProfile
    {
        $profile = $this->findAccessible($user, $id);
        $this->validateDeductionSettings(array_merge($profile->toArray(), $payload));

        $data = [];
        if (array_key_exists('basicSalary', $payload)) {
            $data['basic_salary'] = (float) $payload['basicSalary'];
        }
        if (array_key_exists('defaultAllowance', $payload)) {
            $data['default_allowance'] = (float) $payload['defaultAllowance'];
        }
        if (array_key_exists('defaultDeduction', $payload)) {
            $data['default_deduction'] = (float) $payload['defaultDeduction'];
        }
        if (array_key_exists('overtimeRateType', $payload)) {
            $data['overtime_rate_type'] = (string) $payload['overtimeRateType'];
        }
        if (array_key_exists('overtimeRateValue', $payload)) {
            $data['overtime_rate_value'] = (float) $payload['overtimeRateValue'];
        }
        if (array_key_exists('unpaidLeaveDeductionEnabled', $payload)) {
            $data['unpaid_leave_deduction_enabled'] = (bool) $payload['unpaidLeaveDeductionEnabled'];
        }
        if (array_key_exists('attendanceDeductionEnabled', $payload)) {
            $data['attendance_deduction_enabled'] = (bool) $payload['attendanceDeductionEnabled'];
        }
        if (array_key_exists('attendanceDeductionPerDay', $payload)) {
            $data['attendance_deduction_per_day'] = $payload['attendanceDeductionPerDay'] !== null
                ? (float) $payload['attendanceDeductionPerDay']
                : null;
        }

        if ($data !== []) {
            $profile->update($data);
        }

        return $profile->refresh()->load('employee');
    }

    private function validateDeductionSettings(array $payload): void
    {
        $attendanceEnabled = (bool) ($payload['attendanceDeductionEnabled'] ?? $payload['attendance_deduction_enabled'] ?? false);
        $perDay = $payload['attendanceDeductionPerDay'] ?? $payload['attendance_deduction_per_day'] ?? null;

        if ($attendanceEnabled && ($perDay === null || (float) $perDay <= 0)) {
            throw ValidationException::withMessages([
                'attendanceDeductionPerDay' => ['Attendance deduction per day is required when attendance deduction is enabled.'],
            ]);
        }
    }
}
