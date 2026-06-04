<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\EmployeeLeaveBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeLeaveBalance */
class EmployeeLeaveBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->relationLoaded('leaveType') ? $this->leaveType : null;

        return [
            'id' => (int) $this->id,
            'employeeId' => (int) $this->employee_id,
            'leaveTypeId' => (int) $this->leave_type_id,
            'allocatedDays' => (float) $this->allocated_days,
            'usedDays' => (float) $this->used_days,
            'remainingDays' => (float) $this->remaining_days,
            'leaveType' => $type ? [
                'id' => (int) $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'deductLeaveBalance' => (bool) $type->deduct_leave_balance,
            ] : null,
        ];
    }
}
