<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\AttendanceSyncLog;
use App\Models\Modules\HR\Domain\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceSyncService
{
    public function sync(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $externalRef = $payload['externalRef'];
            $source = $payload['source'] ?? 'fingerprint';
            $payloadHash = hash('sha256', json_encode($payload));

            $existingLog = AttendanceSyncLog::query()
                ->where('external_ref', $externalRef)
                ->first();

            if ($existingLog !== null) {
                $attendance = Attendance::query()
                    ->where('sync_key', $payload['syncKey'] ?? $externalRef)
                    ->first();

                return [
                    'attendance' => $attendance,
                    'duplicate' => true,
                ];
            }

            AttendanceSyncLog::query()->create([
                'source' => $source,
                'external_ref' => $externalRef,
                'payload_hash' => $payloadHash,
                'received_at' => now(),
                'processed_at' => now(),
                'status' => 'processed',
            ]);

            $checkIn = isset($payload['checkIn']) ? Carbon::parse($payload['checkIn']) : null;
            $status = $this->resolveStatus($checkIn, $payload['shiftId'] ?? null);

            $attendance = Attendance::query()->create([
                'employee_id' => $payload['employeeId'],
                'shift_id' => $payload['shiftId'] ?? null,
                'attendance_date' => $payload['attendanceDate'],
                'check_in' => $payload['checkIn'] ?? null,
                'check_out' => $payload['checkOut'] ?? null,
                'source' => 'fingerprint',
                'status' => $status,
                'sync_key' => $payload['syncKey'] ?? $externalRef,
                'notes' => $payload['notes'] ?? null,
            ]);

            return [
                'attendance' => $attendance,
                'duplicate' => false,
            ];
        });
    }

    private function resolveStatus($checkIn, ?int $shiftId): string
    {
        if ($checkIn === null) {
            return 'absent';
        }

        if ($shiftId === null) {
            return 'present';
        }

        $shift = Shift::query()->find($shiftId);
        if ($shift === null) {
            return 'present';
        }

        $shiftStart = Carbon::parse($checkIn->toDateString().' '.$shift->start_time);
        $lateThreshold = $shiftStart->addMinutes((int) $shift->late_tolerance_minutes);

        return $checkIn->gt($lateThreshold) ? 'late' : 'present';
    }
}
