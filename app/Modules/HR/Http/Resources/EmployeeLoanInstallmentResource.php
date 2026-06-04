<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\EmployeeLoanInstallment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeLoanInstallment */
class EmployeeLoanInstallmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'loanId' => (int) $this->loan_id,
            'installmentNo' => (int) $this->installment_no,
            'dueDate' => $this->due_date?->toDateString(),
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'payrollRunItemId' => $this->payroll_run_item_id ? (int) $this->payroll_run_item_id : null,
        ];
    }
}
