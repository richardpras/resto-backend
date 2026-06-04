<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceRecord */
class AttendanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shift = $this->relationLoaded('shift') ? $this->shift : null;
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'employeeId' => (int) $this->employee_id,
            'rosterId' => $this->roster_id !== null ? (int) $this->roster_id : null,
            'shiftId' => $this->shift_id !== null ? (int) $this->shift_id : null,
            'attendanceDate' => $this->attendance_date?->toDateString(),
            'date' => $this->attendance_date?->toDateString(),
            'clockIn' => $this->clock_in?->format('H:i'),
            'clockOut' => $this->clock_out?->format('H:i'),
            'clockInAt' => $this->clock_in?->toIso8601String(),
            'clockOutAt' => $this->clock_out?->toIso8601String(),
            'workedMinutes' => $this->worked_minutes,
            'workedHours' => $this->worked_minutes !== null
                ? round($this->worked_minutes / 60, 2)
                : null,
            'status' => $this->status,
            'source' => $this->source,
            'notes' => $this->notes,
            'importBatchId' => $this->import_batch_id,
            'updatedBy' => $this->updated_by,
            'updatedAt' => $this->updated_at?->toIso8601String(),
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
        ];
    }
}
