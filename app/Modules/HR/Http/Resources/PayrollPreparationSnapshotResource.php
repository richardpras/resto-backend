<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayrollPreparationSnapshot */
class PayrollPreparationSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        return [
            'id' => (int) $this->id,
            'preparationPeriodId' => (int) $this->preparation_period_id,
            'employeeId' => (int) $this->employee_id,
            'scheduledDays' => (int) $this->scheduled_days,
            'attendedDays' => (int) $this->attended_days,
            'absentDays' => (int) $this->absent_days,
            'lateMinutes' => (int) $this->late_minutes,
            'earlyLeaveMinutes' => (int) $this->early_leave_minutes,
            'leaveDays' => (float) $this->leave_days,
            'paidLeaveDays' => (float) $this->paid_leave_days,
            'unpaidLeaveDays' => (float) $this->unpaid_leave_days,
            'overtimeMinutes' => (int) $this->overtime_minutes,
            'overtimeHours' => (float) $this->overtime_hours,
            'reviewRequired' => (bool) $this->review_required,
            'snapshotJson' => $this->snapshot_json,
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
        ];
    }
}
