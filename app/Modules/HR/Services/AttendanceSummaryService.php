<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use Carbon\Carbon;

class AttendanceSummaryService
{
    public function __construct(
        private readonly AttendanceMatchingService $matching,
        private readonly AttendancePeriodService $periodService,
    ) {}

    /**
     * @return array{created: int, updated: int}
     */
    public function generateForDate(string $date, ?int $outletId = null): array
    {
        $created = 0;
        $updated = 0;

        $employeeDates = $this->collectEmployeeDatesForSummary($date, $outletId);

        foreach ($employeeDates as $item) {
            $summary = $this->upsertSummary((int) $item['employee_id'], $date);

            if ($summary->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    public function upsertSummary(int $employeeId, string $date): AttendanceDailySummary
    {
        $existing = AttendanceDailySummary::query()
            ->where('employee_id', $employeeId)
            ->where('attendance_date', $date)
            ->first();

        $outletId = (int) ($existing?->outlet_id ?? 0);
        if ($outletId < 1) {
            $employee = \App\Models\Modules\HR\Domain\Employee::query()->find($employeeId);
            $outletId = (int) ($employee?->outlet_id ?? 0);
        }

        if ($outletId > 0) {
            $this->periodService->assertCanModifyAttendance($outletId, $date);
        }

        $payload = $this->buildSummaryPayload($employeeId, $date);

        return AttendanceDailySummary::query()->updateOrCreate(
            [
                'employee_id' => $employeeId,
                'attendance_date' => $date,
            ],
            $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSummaryPayload(int $employeeId, string $date): array
    {
        $roster = EmployeeRoster::query()
            ->with('shift')
            ->where('employee_id', $employeeId)
            ->where('roster_date', $date)
            ->where('status', EmployeeRoster::STATUS_PUBLISHED)
            ->first();

        $record = AttendanceRecord::query()
            ->where('employee_id', $employeeId)
            ->where('attendance_date', $date)
            ->first();

        $shift = $roster?->shift;
        if ($shift === null && $roster?->shift_id !== null) {
            $shift = $roster->shift()->first();
        }
        if ($shift === null && $record?->shift_id !== null) {
            $shift = $record->shift;
        }

        $scheduledStart = $shift !== null ? $this->normalizeTime($shift->start_time) : null;
        $scheduledEnd = $shift !== null ? $this->normalizeTime($shift->end_time) : null;

        $clockIn = $record?->clock_in ? Carbon::parse($record->clock_in) : null;
        $clockOut = $record?->clock_out ? Carbon::parse($record->clock_out) : null;

        $scheduledWork = $roster !== null && $roster->shift_id !== null;
        $isAbsent = $scheduledWork && $record === null;

        $lateMinutes = 0;
        $earlyLeaveMinutes = 0;
        $workedMinutes = null;
        $isIncomplete = false;

        if ($record !== null) {
            $isIncomplete = $clockIn === null || $clockOut === null;
            $workedMinutes = $this->matching->workedMinutes($clockIn, $clockOut);

            if ($shift !== null && $scheduledStart !== null && $scheduledEnd !== null) {
                $shiftStart = Carbon::parse($date.' '.$scheduledStart);
                $shiftEnd = Carbon::parse($date.' '.$scheduledEnd);
                $graceEnd = $shiftStart->copy()->addMinutes(AttendanceMatchingService::GRACE_MINUTES);

                if ($clockIn !== null && $clockIn->gt($graceEnd)) {
                    $lateMinutes = (int) $graceEnd->diffInMinutes($clockIn);
                }

                if ($clockOut !== null && $clockOut->lt($shiftEnd)) {
                    $earlyLeaveMinutes = (int) $clockOut->diffInMinutes($shiftEnd);
                }
            }
        }

        $requiresReview = $this->requiresReview($record, $isIncomplete, $roster, $clockIn, $clockOut);
        $attendanceStatus = $this->resolveAttendanceStatus(
            $isAbsent,
            $requiresReview,
            $isIncomplete,
            $lateMinutes,
            $earlyLeaveMinutes,
            $clockIn,
            $clockOut,
        );

        $outletId = (int) ($record?->outlet_id ?? $roster?->outlet_id ?? 0);
        if ($outletId < 1 && $record === null && $roster === null) {
            $employee = \App\Models\Modules\HR\Domain\Employee::query()->find($employeeId);
            $outletId = (int) ($employee?->outlet_id ?? 0);
        }

        return [
            'outlet_id' => $outletId,
            'scheduled_shift_id' => $shift?->id ?? $roster?->shift_id,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
            'clock_in' => $clockIn?->format('Y-m-d H:i:s'),
            'clock_out' => $clockOut?->format('Y-m-d H:i:s'),
            'worked_minutes' => $workedMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'is_absent' => $isAbsent,
            'is_incomplete' => $isIncomplete,
            'requires_review' => $requiresReview,
            'attendance_status' => $attendanceStatus,
            'attendance_record_id' => $record?->id,
        ];
    }

    private function requiresReview(
        ?AttendanceRecord $record,
        bool $isIncomplete,
        ?EmployeeRoster $roster,
        ?Carbon $clockIn,
        ?Carbon $clockOut,
    ): bool {
        if ($record === null) {
            return false;
        }

        if ($isIncomplete) {
            return true;
        }

        if ($record->updated_by !== null) {
            return true;
        }

        if (
            in_array($record->source, [AttendanceRecord::SOURCE_CSV_IMPORT, AttendanceRecord::SOURCE_FINGERPRINT], true)
            && $isIncomplete
        ) {
            return true;
        }

        if ($clockIn !== null && $clockOut !== null && $clockOut->lte($clockIn)) {
            return true;
        }

        return false;
    }

    private function resolveAttendanceStatus(
        bool $isAbsent,
        bool $requiresReview,
        bool $isIncomplete,
        int $lateMinutes,
        int $earlyLeaveMinutes,
        ?Carbon $clockIn,
        ?Carbon $clockOut,
    ): string {
        if ($isAbsent) {
            return AttendanceDailySummary::STATUS_ABSENT;
        }

        if ($requiresReview) {
            return AttendanceDailySummary::STATUS_REVIEW_REQUIRED;
        }

        if ($isIncomplete || ($clockIn === null xor $clockOut === null)) {
            return AttendanceDailySummary::STATUS_INCOMPLETE;
        }

        if ($lateMinutes > 0) {
            return AttendanceDailySummary::STATUS_LATE;
        }

        if ($earlyLeaveMinutes > 0) {
            return AttendanceDailySummary::STATUS_EARLY_LEAVE;
        }

        if ($clockIn !== null && $clockOut !== null) {
            return AttendanceDailySummary::STATUS_PRESENT;
        }

        return AttendanceDailySummary::STATUS_INCOMPLETE;
    }

    /**
     * @return list<array{employee_id: int}>
     */
    private function collectEmployeeDatesForSummary(string $date, ?int $outletId): array
    {
        $ids = collect();

        $rosterQuery = EmployeeRoster::query()
            ->where('roster_date', $date)
            ->where('status', EmployeeRoster::STATUS_PUBLISHED)
            ->whereNotNull('shift_id');

        if ($outletId !== null) {
            $rosterQuery->where('outlet_id', $outletId);
        }

        foreach ($rosterQuery->pluck('employee_id') as $employeeId) {
            $ids->push((int) $employeeId);
        }

        $recordQuery = AttendanceRecord::query()->where('attendance_date', $date);
        if ($outletId !== null) {
            $recordQuery->where('outlet_id', $outletId);
        }

        foreach ($recordQuery->pluck('employee_id') as $employeeId) {
            $ids->push((int) $employeeId);
        }

        return $ids->unique()->values()->map(fn (int $id) => ['employee_id' => $id])->all();
    }

    private function normalizeTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = (string) $value;

        return strlen($str) >= 5 ? substr($str, 0, 8) : $str;
    }
}
