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
            'date' => $this->attendance_date?->toDateString(),
            'attendanceDate' => $this->attendance_date?->toDateString(),
            'checkIn' => $this->check_in?->format('H:i'),
            'checkOut' => $this->check_out?->format('H:i'),
            'source' => $this->source,
            'status' => $this->status,
            'syncKey' => $this->sync_key,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
