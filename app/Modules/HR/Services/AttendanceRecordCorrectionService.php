<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manual corrections for {@see AttendanceRecord} (ATTENDANCE-01).
 */
class AttendanceRecordCorrectionService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
        private readonly AttendanceMatchingService $matching,
        private readonly AttendancePeriodService $periodService,
        private readonly AttendanceSummaryService $summaryService,
    ) {}

    public function correct(?User $user, int $recordId, array $payload): AttendanceRecord
    {
        $record = AttendanceRecord::query()
            ->with(['employee', 'shift'])
            ->find($recordId);

        abort_if($record === null, Response::HTTP_NOT_FOUND, 'Attendance record not found.');

        $this->assertRecordAccessible($user, $record);

        $date = $record->attendance_date->toDateString();
        $this->periodService->assertCanModifyAttendance((int) $record->outlet_id, $date);
        $clockIn = array_key_exists('clockIn', $payload)
            ? $this->parseClock($date, $payload['clockIn'])
            : ($record->clock_in ? Carbon::parse($record->clock_in) : null);
        $clockOut = array_key_exists('clockOut', $payload)
            ? $this->parseClock($date, $payload['clockOut'])
            : ($record->clock_out ? Carbon::parse($record->clock_out) : null);

        $shift = $record->shift;
        if ($shift === null && $record->shift_id !== null) {
            $match = $this->matching->resolveRosterAndShift((int) $record->employee_id, $date);
            $shift = $match['shift'];
            if ($record->roster_id === null && $match['roster'] !== null) {
                $record->roster_id = $match['roster']->id;
            }
            if ($record->shift_id === null && $shift !== null) {
                $record->shift_id = $shift->id;
            }
        }

        $calc = $this->matching->calculateStatusAndWorkedMinutes($clockIn, $clockOut, $shift, $date);

        $record->fill([
            'clock_in' => $clockIn?->format('Y-m-d H:i:s'),
            'clock_out' => $clockOut?->format('Y-m-d H:i:s'),
            'worked_minutes' => $calc['worked_minutes'],
            'status' => $calc['status'],
            'notes' => array_key_exists('notes', $payload) ? $payload['notes'] : $record->notes,
            'updated_by' => $user?->id,
        ]);
        $record->save();

        $this->summaryService->upsertSummary((int) $record->employee_id, $date);

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

    private function assertRecordAccessible(?User $user, AttendanceRecord $record): void
    {
        if ($user === null) {
            return;
        }

        $record->loadMissing('employee');

        try {
            $this->employeeMaster->assertEmployeeOutletAllowed($user, $record->employee);
        } catch (ValidationException) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot access attendance for this outlet.');
        }
    }
}
