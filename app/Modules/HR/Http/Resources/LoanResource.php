<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'employeeId' => (int) $this->employee_id,
            'amount' => (float) $this->amount,
            'installments' => (int) $this->installments,
            'paidInstallments' => (int) $this->paid_installments,
            'startDate' => $this->start_date?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status,
        ];
    }
}
