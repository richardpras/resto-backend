<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\EmployeeCashAdvance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeCashAdvance */
class EmployeeCashAdvanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;
        $installments = $this->relationLoaded('installments') ? $this->installments : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'employeeId' => (int) $this->employee_id,
            'advanceNo' => $this->advance_no,
            'amount' => (float) $this->amount,
            'repaymentType' => $this->repayment_type,
            'installmentCount' => $this->installment_count !== null ? (int) $this->installment_count : null,
            'installmentAmount' => $this->installment_amount !== null ? (float) $this->installment_amount : null,
            'deductedAmount' => (float) $this->deducted_amount,
            'remainingAmount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'approvedBy' => $this->approved_by ? (int) $this->approved_by : null,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
            'installments' => $installments
                ? EmployeeCashAdvanceInstallmentResource::collection($installments)
                : null,
        ];
    }
}
