<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\PayrollAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayrollAdjustment */
class PayrollAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'employeeId' => (int) $this->employee_id,
            'adjustmentNo' => $this->adjustment_no,
            'type' => $this->type,
            'category' => $this->category,
            'amount' => (float) $this->amount,
            'effectiveFrom' => $this->effective_from?->toDateString(),
            'effectiveTo' => $this->effective_to?->toDateString(),
            'status' => $this->status,
            'approvedBy' => $this->approved_by ? (int) $this->approved_by : null,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'description' => $this->description,
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
        ];
    }
}
