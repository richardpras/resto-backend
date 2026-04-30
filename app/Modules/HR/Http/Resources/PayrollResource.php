<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'tenantId' => $this->tenant_id,
            'employeeId' => $this->employee_id,
            'periodStart' => $this->period_start?->toDateString(),
            'periodEnd' => $this->period_end?->toDateString(),
            'baseAmount' => (float) $this->base_amount,
            'adjustmentAmount' => (float) $this->adjustment_amount,
            'deductionAmount' => (float) $this->deduction_amount,
            'netAmount' => (float) $this->net_amount,
            'status' => $this->status,
            'journalId' => $this->journal_id,
            'adjustments' => $this->adjustments,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
