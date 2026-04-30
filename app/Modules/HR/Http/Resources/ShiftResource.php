<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'tenantId' => $this->tenant_id,
            'code' => $this->code,
            'name' => $this->name,
            'startTime' => $this->start_time,
            'endTime' => $this->end_time,
            'lateToleranceMinutes' => (int) $this->late_tolerance_minutes,
            'overtimeAfterMinutes' => (int) $this->overtime_after_minutes,
            'active' => (bool) $this->active,
        ];
    }
}
