<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\EmployeeRoster;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeRoster */
class EmployeeRosterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shift = $this->relationLoaded('shift') ? $this->shift : null;
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'employeeId' => (int) $this->employee_id,
            'shiftId' => $this->shift_id !== null ? (int) $this->shift_id : null,
            'rosterDate' => $this->roster_date?->toDateString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
                'departmentId' => $employee->department_id !== null ? (int) $employee->department_id : null,
            ] : null,
            'shift' => $shift ? [
                'id' => (int) $shift->id,
                'code' => $shift->code,
                'name' => $shift->name,
                'startTime' => $this->formatTime($shift->start_time),
                'endTime' => $this->formatTime($shift->end_time),
            ] : null,
        ];
    }

    private function formatTime(mixed $value): string
    {
        $str = (string) $value;

        return strlen($str) >= 5 ? substr($str, 0, 5) : $str;
    }
}
