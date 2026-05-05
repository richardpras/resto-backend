<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'employeeId' => (int) $this->employee_id,
            'type' => $this->type,
            'category' => $this->category,
            'amount' => (float) $this->amount,
            'date' => $this->date?->toDateString(),
            'notes' => $this->notes,
        ];
    }
}
