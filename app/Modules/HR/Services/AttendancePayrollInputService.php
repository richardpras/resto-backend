<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Attendance;
use Carbon\Carbon;

class AttendancePayrollInputService
{
    public function summarizeForPeriod(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $attendances = Attendance::query()
            ->with('shift')
            ->where('employee_id', $employeeId)
            ->whereBetween('attendance_date', [$periodStart, $periodEnd])
            ->get();

        $lateCount = (int) $attendances->where('status', 'late')->count();
        $absentCount = (int) $attendances->where('status', 'absent')->count();

        $overtimeMinutes = (int) $attendances->sum(function (Attendance $attendance): int {
            if ($attendance->check_out === null || $attendance->shift === null) {
                return 0;
            }

            $shiftEnd = Carbon::parse($attendance->attendance_date->toDateString().' '.$attendance->shift->end_time);
            $checkOut = Carbon::parse($attendance->check_out);
            $workedAfterShift = $checkOut->greaterThan($shiftEnd) ? $shiftEnd->diffInMinutes($checkOut) : 0;
            $threshold = (int) ($attendance->shift->overtime_after_minutes ?? 0);

            return max($workedAfterShift - $threshold, 0);
        });

        return [
            'lateCount' => $lateCount,
            'absentCount' => $absentCount,
            'overtimeMinutes' => $overtimeMinutes,
        ];
    }
}
