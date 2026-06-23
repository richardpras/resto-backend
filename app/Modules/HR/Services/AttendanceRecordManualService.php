<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AttendanceRecordManualService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
        private readonly AttendanceMatchingService $matching,
        private readonly AttendancePeriodService $periodService,
        private readonly AttendanceSummaryService $summaryService,
    ) {}

    public function create(?User $user, array $payload): AttendanceRecord
    {
        $employee = Employee::query()->find((int) ($payload['employeeId'] ?? 0));
        abort_if($employee === null, Response::HTTP_NOT_FOUND, 'Employee not found.');

        $this->employeeMaster->assertEmployeeOutletAllowed($user, $employee);

        $date = Carbon::parse((string) $payload['date'])->toDateString();
        $this->periodService->assertCanModifyAttendance((int) $employee->outlet_id, $date);

        $existing = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $date)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'date' => ['Attendance already exists for this employee on this date.'],
            ]);
        }

        $clockIn = array_key_exists('clockIn', $payload)
            ? $this->parseClock($date, $payload['clockIn'])
            : null;
        $clockOut = array_key_exists('clockOut', $payload)
            ? $this->parseClock($date, $payload['clockOut'])
            : null;

        $match = $this->matching->resolveRosterAndShift((int) $employee->id, $date);
        $shift = $match['shift'];
        $calc = $this->matching->calculateStatusAndWorkedMinutes($clockIn, $clockOut, $shift, $date);

        $status = isset($payload['status']) && $payload['status'] !== ''
            ? (string) $payload['status']
            : $calc['status'];

        $record = AttendanceRecord::query()->create([
            'outlet_id' => (int) $employee->outlet_id,
            'employee_id' => (int) $employee->id,
            'roster_id' => $match['roster']?->id,
            'shift_id' => $shift?->id ?? $match['roster']?->shift_id,
            'attendance_date' => $date,
            'clock_in' => $clockIn?->format('Y-m-d H:i:s'),
            'clock_out' => $clockOut?->format('Y-m-d H:i:s'),
            'worked_minutes' => $calc['worked_minutes'],
            'status' => $status,
            'source' => AttendanceRecord::SOURCE_MANUAL,
            'notes' => $payload['notes'] ?? null,
            'updated_by' => $user?->id,
        ]);

        $this->summaryService->upsertSummary((int) $employee->id, $date);

        return $record->refresh()->load(['employee', 'shift', 'roster']);
    }

    private function parseClock(string $date, mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $str = (string) $value;
        if (preg_match('/^\d{2}:\d{2}$/', $str) === 1) {
            return Carbon::parse($date.' '.$str.':00');
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $str) === 1) {
            return Carbon::parse($date.' '.$str);
        }

        return Carbon::parse($str);
    }
}
