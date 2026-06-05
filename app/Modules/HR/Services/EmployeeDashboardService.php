<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeLeaveBalance;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\EmployeeUser;
use App\Models\Modules\HR\Domain\PayrollPayslip;

class EmployeeDashboardService
{
    public function __construct(
        private readonly EmployeeProfileService $profiles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboardForEmployeeUser(EmployeeUser $user): array
    {
        $employeeId = (int) $user->employee_id;
        $employee = Employee::query()->find($employeeId);
        abort_if($employee === null, 404, 'Employee record not found.');

        $profile = $this->profiles->profileForEmployeeUser($user);
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $todayRoster = EmployeeRoster::query()
            ->with('shift')
            ->where('employee_id', $employeeId)
            ->whereDate('roster_date', $today)
            ->where('status', EmployeeRoster::STATUS_PUBLISHED)
            ->orderBy('id')
            ->first();

        $upcoming = EmployeeRoster::query()
            ->with('shift')
            ->where('employee_id', $employeeId)
            ->where('status', EmployeeRoster::STATUS_PUBLISHED)
            ->whereDate('roster_date', '>', $today)
            ->orderBy('roster_date')
            ->limit(7)
            ->get();

        $summaries = AttendanceDailySummary::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('attendance_date', [$monthStart, $monthEnd])
            ->get();

        $leaveBalances = EmployeeLeaveBalance::query()
            ->with('leaveType')
            ->where('employee_id', $employeeId)
            ->get();

        $latestPayslip = PayrollPayslip::query()
            ->with('payrollPeriod')
            ->where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->first();

        return [
            'employee' => $profile['employee'],
            'todaySchedule' => $todayRoster ? [
                'rosterDate' => $todayRoster->roster_date?->toDateString(),
                'shift' => $todayRoster->shift ? [
                    'id' => (int) $todayRoster->shift->id,
                    'name' => $todayRoster->shift->name,
                    'startTime' => $todayRoster->shift->start_time,
                    'endTime' => $todayRoster->shift->end_time,
                ] : null,
            ] : null,
            'attendanceSummary' => [
                'periodStart' => $monthStart,
                'periodEnd' => $monthEnd,
                'presentDays' => $summaries->where('is_absent', false)->count(),
                'absentDays' => $summaries->where('is_absent', true)->count(),
                'lateCount' => $summaries->where('attendance_status', AttendanceDailySummary::STATUS_LATE)->count(),
                'totalDays' => $summaries->count(),
            ],
            'leaveBalanceSummary' => $leaveBalances->map(fn (EmployeeLeaveBalance $b) => [
                'leaveTypeId' => (int) $b->leave_type_id,
                'leaveTypeName' => $b->leaveType?->name,
                'allocatedDays' => (float) $b->allocated_days,
                'usedDays' => (float) $b->used_days,
                'remainingDays' => (float) $b->remaining_days,
            ])->values()->all(),
            'upcomingShifts' => $upcoming->map(fn (EmployeeRoster $r) => [
                'rosterDate' => $r->roster_date?->toDateString(),
                'shift' => $r->shift ? [
                    'id' => (int) $r->shift->id,
                    'name' => $r->shift->name,
                    'startTime' => $r->shift->start_time,
                    'endTime' => $r->shift->end_time,
                ] : null,
            ])->values()->all(),
            'latestPayslip' => $latestPayslip ? [
                'id' => (int) $latestPayslip->id,
                'payslipNo' => $latestPayslip->payslip_no,
                'status' => $latestPayslip->status,
                'netSalary' => (float) $latestPayslip->net_salary,
                'grossSalary' => (float) $latestPayslip->gross_salary,
                'publishedAt' => $latestPayslip->published_at?->toIso8601String(),
                'period' => $latestPayslip->payrollPeriod ? [
                    'periodStart' => $latestPayslip->payrollPeriod->period_start?->toDateString(),
                    'periodEnd' => $latestPayslip->payrollPeriod->period_end?->toDateString(),
                ] : null,
            ] : null,
            'notifications' => [],
        ];
    }
}
