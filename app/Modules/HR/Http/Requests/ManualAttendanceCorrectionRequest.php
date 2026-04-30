<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attendanceDate' => ['nullable', 'date'],
            'checkIn' => ['nullable', 'date'],
            'checkOut' => ['nullable', 'date', 'after_or_equal:checkIn'],
            'status' => ['nullable', 'in:present,late,absent'],
            'notes' => ['nullable', 'string'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
