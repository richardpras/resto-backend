<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\EmployeeReimbursement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeReimbursement */
class EmployeeReimbursementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'employeeId' => (int) $this->employee_id,
            'claimNo' => $this->claim_no,
            'category' => $this->category,
            'title' => $this->title,
            'description' => $this->description,
            'claimAmount' => (float) $this->claim_amount,
            'expenseDate' => $this->expense_date?->toDateString(),
            'status' => $this->status,
            'submittedAt' => $this->submitted_at?->toDateTimeString(),
            'approvedAt' => $this->approved_at?->toDateTimeString(),
            'rejectedAt' => $this->rejected_at?->toDateTimeString(),
            'paidAt' => $this->paid_at?->toDateTimeString(),
            'approvedBy' => $this->approved_by,
            'rejectedBy' => $this->rejected_by,
            'payrollRunItemId' => $this->payroll_run_item_id,
            'notes' => $this->notes,
            'attachments' => EmployeeReimbursementAttachmentResource::collection($this->whenLoaded('attachments')),
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
        ];
    }
}
