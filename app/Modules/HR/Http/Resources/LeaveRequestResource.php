<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveRequest */
class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;
        $type = $this->relationLoaded('leaveType') ? $this->leaveType : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'employeeId' => (int) $this->employee_id,
            'leaveTypeId' => (int) $this->leave_type_id,
            'startDate' => $this->start_date?->toDateString(),
            'endDate' => $this->end_date?->toDateString(),
            'totalDays' => (int) $this->total_days,
            'reason' => $this->reason,
            'attachmentPath' => $this->attachment_path,
            'status' => $this->status,
            'approvedBy' => $this->approved_by,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'rejectedBy' => $this->rejected_by,
            'rejectedAt' => $this->rejected_at?->toIso8601String(),
            'rejectionReason' => $this->rejection_reason,
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
            'leaveType' => $type ? [
                'id' => (int) $type->id,
                'code' => $type->code,
                'name' => $type->name,
            ] : null,
        ];
    }
}
