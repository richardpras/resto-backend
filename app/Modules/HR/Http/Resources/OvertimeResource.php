<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OvertimeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'employeeId' => (int) $this->employee_id,
            'date' => $this->date?->toDateString(),
            'hours' => (float) $this->hours,
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
