<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'employeeId' => $this->employee_id,
            'shiftId' => $this->shift_id,
            'attendanceDate' => $this->attendance_date?->toDateString(),
            'checkIn' => $this->check_in?->toISOString(),
            'checkOut' => $this->check_out?->toISOString(),
            'source' => $this->source,
            'status' => $this->status,
            'syncKey' => $this->sync_key,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
