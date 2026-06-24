<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendancePeriodLock */
class AttendancePeriodLockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'periodStart' => $this->period_start?->toDateString(),
            'periodEnd' => $this->period_end?->toDateString(),
            'periodLabel' => $this->period_start?->toDateString().' → '.$this->period_end?->toDateString(),
            'status' => $this->status,
            'approvedBy' => $this->approved_by,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'lockedBy' => $this->locked_by,
            'lockedAt' => $this->locked_at?->toIso8601String(),
            'notes' => $this->notes,
            'payrollPreparationPeriodId' => $this->payroll_preparation_period_id !== null
                ? (int) $this->payroll_preparation_period_id
                : null,
            'isLinkedToPayrollMaster' => $this->payroll_preparation_period_id !== null,
            'employeeCount' => $this->when(
                isset($this->employee_count),
                fn () => (int) $this->employee_count,
            ),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
