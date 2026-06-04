<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\EmployeeShiftAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeShiftAssignment */
class EmployeeShiftAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shift = $this->relationLoaded('shift') ? $this->shift : null;
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'employeeId' => (int) $this->employee_id,
            'shiftId' => (int) $this->shift_id,
            'effectiveFrom' => $this->effective_from?->toDateString(),
            'effectiveUntil' => $this->effective_until?->toDateString(),
            'isActive' => (bool) $this->is_active,
            'notes' => $this->notes,
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
            'shift' => $shift ? [
                'id' => (int) $shift->id,
                'code' => $shift->code,
                'name' => $shift->name,
                'startTime' => $this->formatTime($shift->start_time),
                'endTime' => $this->formatTime($shift->end_time),
            ] : null,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function formatTime(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;

        return strlen($str) >= 5 ? substr($str, 0, 5) : $str;
    }
}
