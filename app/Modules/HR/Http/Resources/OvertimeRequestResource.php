<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\OvertimeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OvertimeRequest */
class OvertimeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;
        $type = $this->relationLoaded('overtimeType') ? $this->overtimeType : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'employeeId' => (int) $this->employee_id,
            'overtimeTypeId' => (int) $this->overtime_type_id,
            'overtimeDate' => $this->overtime_date?->toDateString(),
            'startTime' => substr((string) $this->start_time, 0, 5),
            'endTime' => substr((string) $this->end_time, 0, 5),
            'totalMinutes' => (int) $this->total_minutes,
            'totalHours' => (float) $this->total_hours,
            'reason' => $this->reason,
            'status' => $this->status,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'rejectedAt' => $this->rejected_at?->toIso8601String(),
            'rejectionReason' => $this->rejection_reason,
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
            'overtimeType' => $type ? [
                'id' => (int) $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'multiplier' => (float) $type->multiplier,
            ] : null,
        ];
    }
}
