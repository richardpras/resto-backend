<?php

namespace App\Modules\HR\Http\Requests;

use App\Models\Modules\HR\Domain\AttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'clockIn' => ['nullable', 'string'],
            'clockOut' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in([
                AttendanceRecord::STATUS_PRESENT,
                AttendanceRecord::STATUS_LATE,
                AttendanceRecord::STATUS_EARLY_LEAVE,
                AttendanceRecord::STATUS_INCOMPLETE,
                AttendanceRecord::STATUS_ABSENT,
            ])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
