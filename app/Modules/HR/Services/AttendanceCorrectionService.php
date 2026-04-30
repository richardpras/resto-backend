<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\AttendanceAuditLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AttendanceCorrectionService
{
    public function correct(int $attendanceId, array $payload, int $actorUserId): Attendance
    {
        return DB::transaction(function () use ($attendanceId, $payload, $actorUserId) {
            $attendance = Attendance::query()->find($attendanceId);
            abort_if($attendance === null, Response::HTTP_NOT_FOUND, 'Attendance not found.');

            $before = [
                'attendance_date' => $attendance->attendance_date?->toDateString(),
                'check_in' => $attendance->check_in?->toISOString(),
                'check_out' => $attendance->check_out?->toISOString(),
                'status' => $attendance->status,
                'notes' => $attendance->notes,
            ];

            $attendance->fill([
                'attendance_date' => $payload['attendanceDate'] ?? $attendance->attendance_date,
                'check_in' => $payload['checkIn'] ?? $attendance->check_in,
                'check_out' => $payload['checkOut'] ?? $attendance->check_out,
                'status' => $payload['status'] ?? $attendance->status,
                'notes' => $payload['notes'] ?? $attendance->notes,
            ]);
            $attendance->save();

            $after = [
                'attendance_date' => $attendance->attendance_date?->toDateString(),
                'check_in' => $attendance->check_in?->toISOString(),
                'check_out' => $attendance->check_out?->toISOString(),
                'status' => $attendance->status,
                'notes' => $attendance->notes,
            ];

            AttendanceAuditLog::query()->create([
                'attendance_id' => $attendance->id,
                'actor_user_id' => $actorUserId,
                'action' => 'manual-correction',
                'before_json' => $before,
                'after_json' => $after,
                'reason' => $payload['reason'],
                'source_type' => 'manual-edit',
            ]);

            return $attendance->refresh();
        });
    }
}
