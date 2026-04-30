<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => ['nullable', 'string', 'max:50'],
            'externalRef' => ['required', 'string', 'max:191'],
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'attendanceDate' => ['required', 'date'],
            'checkIn' => ['nullable', 'date'],
            'checkOut' => ['nullable', 'date', 'after_or_equal:checkIn'],
            'syncKey' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
