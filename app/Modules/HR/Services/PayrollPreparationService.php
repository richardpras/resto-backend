<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\LeaveRequest;
use App\Models\Modules\HR\Domain\OvertimeDailySummary;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only consolidation of approved HR data into payroll-ready snapshots.
 */
class PayrollPreparationService
{
    public function __construct(
        private readonly AttendancePeriodService $attendancePeriods,
    ) {}

    /**
     * @return Collection<int, PayrollPreparationSnapshot>
     */
    public function generateSnapshots(PayrollPreparationPeriod $period): Collection
    {
        $periodStart = $period->period_start->toDateString();
        $periodEnd = $period->period_end->toDateString();
        $outletId = (int) $period->outlet_id;

        $attendancePeriod = $this->attendancePeriods->findExactPeriod($outletId, $periodStart, $periodEnd);
        $attendancePeriodApproved = $attendancePeriod !== null
            && in_array($attendancePeriod->status, [
                AttendancePeriodLock::STATUS_APPROVED,
                AttendancePeriodLock::STATUS_LOCKED,
            ], true);

        $employeeIds = Employee::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $snapshots = collect();

        foreach ($employeeIds as $employeeId) {
            $metrics = $this->buildEmployeeMetrics(
                $employeeId,
                $outletId,
                $periodStart,
                $periodEnd,
                $attendancePeriod,
                $attendancePeriodApproved,
            );

            $snapshots->push(
                PayrollPreparationSnapshot::query()->updateOrCreate(
                    [
                        'preparation_period_id' => $period->id,
                        'employee_id' => $employeeId,
                    ],
                    $metrics,
                ),
            );
        }

        $period->update(['generated_at' => now()]);

        return $snapshots;
    }

    /**
     * @return array<string, mixed>
     */
    public function periodSummary(PayrollPreparationPeriod $period): array
    {
        $rows = PayrollPreparationSnapshot::query()
            ->where('preparation_period_id', $period->id)
            ->get();

        return [
            'employeeCount' => $rows->count(),
            'attendanceDays' => (int) $rows->sum('attended_days'),
            'leaveDays' => round((float) $rows->sum('leave_days'), 2),
            'overtimeHours' => round((float) $rows->sum('overtime_hours'), 2),
            'reviewRequiredCount' => $rows->where('review_required', true)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEmployeeMetrics(
        int $employeeId,
        int $outletId,
        string $periodStart,
        string $periodEnd,
        ?AttendancePeriodLock $attendancePeriod,
        bool $attendancePeriodApproved,
    ): array {
        $scheduledDays = (int) EmployeeRoster::query()
            ->where('employee_id', $employeeId)
            ->where('outlet_id', $outletId)
            ->where('status', 'published')
            ->whereBetween('roster_date', [$periodStart, $periodEnd])
            ->count();

        $summaryQuery = AttendanceDailySummary::query()
            ->where('employee_id', $employeeId)
            ->where('outlet_id', $outletId)
            ->whereBetween('attendance_date', [$periodStart, $periodEnd]);

        $summaries = $attendancePeriodApproved || $attendancePeriod === null
            ? $summaryQuery->get()
            : collect();

        $attendedDays = 0;
        $absentDays = 0;
        $lateMinutes = 0;
        $earlyLeaveMinutes = 0;
        $reviewRequired = false;

        foreach ($summaries as $summary) {
            if ($summary->requires_review) {
                $reviewRequired = true;
            }

            if ($summary->is_absent) {
                $absentDays++;
            }

            $hasPunch = $summary->clock_in !== null || $summary->clock_out !== null;
            if ($hasPunch && ! $summary->is_absent) {
                $attendedDays++;
            }

            $lateMinutes += (int) $summary->late_minutes;
            $earlyLeaveMinutes += (int) $summary->early_leave_minutes;
        }

        if ($attendancePeriod !== null && ! $attendancePeriodApproved) {
            $reviewRequired = true;
        }

        $leaveMetrics = $this->leaveDaysInPeriod($employeeId, $periodStart, $periodEnd);

        $overtimeRows = OvertimeDailySummary::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('overtime_date', [$periodStart, $periodEnd])
            ->get();

        $overtimeMinutes = (int) $overtimeRows->sum('approved_minutes');
        $overtimeHours = round($overtimeMinutes / 60, 2);

        return [
            'scheduled_days' => $scheduledDays,
            'attended_days' => $attendedDays,
            'absent_days' => $absentDays,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'leave_days' => $leaveMetrics['leaveDays'],
            'paid_leave_days' => $leaveMetrics['paidLeaveDays'],
            'unpaid_leave_days' => $leaveMetrics['unpaidLeaveDays'],
            'overtime_minutes' => $overtimeMinutes,
            'overtime_hours' => $overtimeHours,
            'review_required' => $reviewRequired,
            'snapshot_json' => [
                'attendancePeriodId' => $attendancePeriod?->id,
                'attendancePeriodStatus' => $attendancePeriod?->status,
                'attendancePeriodApproved' => $attendancePeriodApproved,
                'summaryRowCount' => $summaries->count(),
                'overtimeRequestCount' => (int) $overtimeRows->sum('request_count'),
            ],
        ];
    }

    /**
     * @return array{leaveDays: float, paidLeaveDays: float, unpaidLeaveDays: float}
     */
    private function leaveDaysInPeriod(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $requests = LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employeeId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $periodEnd)
            ->where('end_date', '>=', $periodStart)
            ->get();

        $leaveDays = 0.0;
        $paidLeaveDays = 0.0;
        $unpaidLeaveDays = 0.0;

        foreach ($requests as $request) {
            $rangeStart = Carbon::parse(max($request->start_date->toDateString(), $periodStart));
            $rangeEnd = Carbon::parse(min($request->end_date->toDateString(), $periodEnd));
            $daysInPeriod = (int) $rangeStart->diffInDays($rangeEnd) + 1;

            $leaveDays += $daysInPeriod;
            $isPaid = $request->leaveType?->paid_leave ?? true;

            if ($isPaid) {
                $paidLeaveDays += $daysInPeriod;
            } else {
                $unpaidLeaveDays += $daysInPeriod;
            }
        }

        return [
            'leaveDays' => round($leaveDays, 2),
            'paidLeaveDays' => round($paidLeaveDays, 2),
            'unpaidLeaveDays' => round($unpaidLeaveDays, 2),
        ];
    }
}
