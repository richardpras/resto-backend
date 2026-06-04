<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\EmployeeLoan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeLoan */
class EmployeeLoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;
        $installments = $this->relationLoaded('installments') ? $this->installments : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'employeeId' => (int) $this->employee_id,
            'loanNo' => $this->loan_no,
            'principalAmount' => (float) $this->principal_amount,
            'installmentAmount' => (float) $this->installment_amount,
            'totalInstallments' => (int) $this->total_installments,
            'paidInstallments' => (int) $this->paid_installments,
            'remainingBalance' => (float) $this->remaining_balance,
            'status' => $this->status,
            'approvedBy' => $this->approved_by ? (int) $this->approved_by : null,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
            'installments' => $installments
                ? EmployeeLoanInstallmentResource::collection($installments)
                : null,
        ];
    }
}
