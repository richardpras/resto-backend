<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveType */
class LeaveTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'code' => $this->code,
            'name' => $this->name,
            'requiresAttachment' => (bool) $this->requires_attachment,
            'deductLeaveBalance' => (bool) $this->deduct_leave_balance,
            'paidLeave' => (bool) $this->paid_leave,
            'isActive' => (bool) $this->is_active,
        ];
    }
}
