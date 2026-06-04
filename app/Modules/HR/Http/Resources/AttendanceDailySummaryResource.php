<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceDailySummary */
class AttendanceDailySummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;
        $shift = $this->relationLoaded('shift') ? $this->shift : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'employeeId' => (int) $this->employee_id,
            'attendanceDate' => $this->attendance_date?->toDateString(),
            'scheduledShiftId' => $this->scheduled_shift_id,
            'scheduledStart' => $this->formatTime($this->scheduled_start),
            'scheduledEnd' => $this->formatTime($this->scheduled_end),
            'clockIn' => $this->clock_in?->format('H:i'),
            'clockOut' => $this->clock_out?->format('H:i'),
            'workedMinutes' => $this->worked_minutes,
            'workedHours' => $this->worked_minutes !== null
                ? round($this->worked_minutes / 60, 2)
                : null,
            'lateMinutes' => (int) $this->late_minutes,
            'earlyLeaveMinutes' => (int) $this->early_leave_minutes,
            'isAbsent' => (bool) $this->is_absent,
            'isIncomplete' => (bool) $this->is_incomplete,
            'requiresReview' => (bool) $this->requires_review,
            'attendanceStatus' => $this->attendance_status,
            'attendanceRecordId' => $this->attendance_record_id,
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
            'shift' => $shift ? [
                'id' => (int) $shift->id,
                'name' => $shift->name,
                'startTime' => substr((string) $shift->start_time, 0, 5),
                'endTime' => substr((string) $shift->end_time, 0, 5),
            ] : null,
            'reviews' => $this->whenLoaded(
                'reviews',
                fn () => AttendanceReviewResource::collection($this->reviews),
            ),
        ];
    }

    private function formatTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return substr((string) $value, 0, 5);
    }
}
