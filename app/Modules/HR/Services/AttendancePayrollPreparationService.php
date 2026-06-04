<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read-only payroll preparation metrics — no deductions or payroll runs.
 */
class AttendancePayrollPreparationService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
        private readonly AttendancePeriodService $periodService,
        private readonly OvertimeSummaryService $overtimeSummaries,
    ) {}

    /**
     * @return array{meta: array<string, mixed>, employees: Collection<int, array<string, mixed>>}
     */
    public function forPeriodWithMeta(?User $user, array $filters): array
    {
        $periodStart = (string) ($filters['periodStart'] ?? '');
        $periodEnd = (string) ($filters['periodEnd'] ?? '');
        abort_if($periodStart === '' || $periodEnd === '', 422, 'periodStart and periodEnd are required.');

        $outletId = ! empty($filters['outletId']) ? (int) $filters['outletId'] : null;
        $meta = $outletId !== null
            ? $this->periodService->periodMetaForRange($outletId, $periodStart, $periodEnd)
            : [
                'lockStatus' => null,
                'approvedAt' => null,
                'lockedAt' => null,
                'periodId' => null,
            ];

        return [
            'meta' => $meta,
            'employees' => $this->forPeriod($user, $filters),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forPeriod(?User $user, array $filters): Collection
    {
        $periodStart = (string) ($filters['periodStart'] ?? '');
        $periodEnd = (string) ($filters['periodEnd'] ?? '');
        abort_if($periodStart === '' || $periodEnd === '', 422, 'periodStart and periodEnd are required.');

        $query = AttendanceDailySummary::query()
            ->with('employee')
            ->whereBetween('attendance_date', [$periodStart, $periodEnd]);

        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');

        if (! empty($filters['outletId'])) {
            $query->where('outlet_id', (int) $filters['outletId']);
        }

        $rows = $query->get();

        return $rows->groupBy('employee_id')->map(function (Collection $group) use ($periodStart, $periodEnd) {
            $first = $group->first();
            $employee = $first->employee;

            $withAttendance = $group->filter(
                fn (AttendanceDailySummary $s) => $s->clock_in !== null || $s->clock_out !== null,
            );

            $overtime = $this->overtimeSummaries->periodTotalsForEmployee(
                (int) $first->employee_id,
                $periodStart,
                $periodEnd,
            );

            return [
                'employeeId' => (int) $first->employee_id,
                'employeeNo' => $employee?->employee_no,
                'fullName' => $employee?->full_name,
                'outletId' => (int) $first->outlet_id,
                'attendanceDays' => $withAttendance->count(),
                'absentDays' => $group->where('is_absent', true)->count(),
                'lateCount' => $group->where('late_minutes', '>', 0)->count(),
                'lateMinutes' => (int) $group->sum('late_minutes'),
                'earlyLeaveCount' => $group->where('early_leave_minutes', '>', 0)->count(),
                'workedMinutes' => (int) $group->sum(fn (AttendanceDailySummary $s) => (int) ($s->worked_minutes ?? 0)),
                'overtimeMinutes' => $overtime['minutes'],
                'overtimeHours' => $overtime['hours'],
            ];
        })->values();
    }
}
