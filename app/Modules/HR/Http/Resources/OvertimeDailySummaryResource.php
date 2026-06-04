<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\OvertimeDailySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OvertimeDailySummary */
class OvertimeDailySummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        return [
            'id' => (int) $this->id,
            'employeeId' => (int) $this->employee_id,
            'overtimeDate' => $this->overtime_date?->toDateString(),
            'approvedMinutes' => (int) $this->approved_minutes,
            'approvedHours' => (float) $this->approved_hours,
            'requestCount' => (int) $this->request_count,
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
        ];
    }
}
