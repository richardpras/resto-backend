<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\Shift;
use Carbon\Carbon;

class AttendanceMatchingService
{
    public const GRACE_MINUTES = 5;

    /**
     * @return array{roster: ?EmployeeRoster, shift: ?Shift}
     */
    public function resolveRosterAndShift(int $employeeId, string $date): array
    {
        $roster = EmployeeRoster::query()
            ->with('shift')
            ->where('employee_id', $employeeId)
            ->where('roster_date', $date)
            ->first();

        $shift = $roster?->shift;
        if ($shift === null && $roster?->shift_id !== null) {
            $shift = Shift::query()->find((int) $roster->shift_id);
        }

        return ['roster' => $roster, 'shift' => $shift];
    }

    /**
     * @return array{status: string, worked_minutes: ?int}
     */
    public function calculateStatusAndWorkedMinutes(
        ?Carbon $clockIn,
        ?Carbon $clockOut,
        ?Shift $shift,
        string $date,
    ): array {
        if ($clockIn === null xor $clockOut === null) {
            return [
                'status' => AttendanceRecord::STATUS_INCOMPLETE,
                'worked_minutes' => $this->workedMinutes($clockIn, $clockOut),
            ];
        }

        if ($clockIn === null && $clockOut === null) {
            return [
                'status' => AttendanceRecord::STATUS_INCOMPLETE,
                'worked_minutes' => null,
            ];
        }

        $worked = $this->workedMinutes($clockIn, $clockOut);
        $status = AttendanceRecord::STATUS_PRESENT;

        if ($shift !== null) {
            $shiftStart = Carbon::parse($date.' '.$this->normalizeTime($shift->start_time));
            $shiftEnd = Carbon::parse($date.' '.$this->normalizeTime($shift->end_time));

            if ($clockIn !== null && $clockIn->gt($shiftStart->copy()->addMinutes(self::GRACE_MINUTES))) {
                $status = AttendanceRecord::STATUS_LATE;
            }

            if ($clockOut !== null && $clockOut->lt($shiftEnd)) {
                $status = $status === AttendanceRecord::STATUS_LATE
                    ? AttendanceRecord::STATUS_LATE
                    : AttendanceRecord::STATUS_EARLY_LEAVE;
            }
        }

        return [
            'status' => $status,
            'worked_minutes' => $worked,
        ];
    }

    public function workedMinutes(?Carbon $clockIn, ?Carbon $clockOut): ?int
    {
        if ($clockIn === null || $clockOut === null) {
            return null;
        }

        if ($clockOut->lte($clockIn)) {
            return 0;
        }

        return (int) $clockIn->diffInMinutes($clockOut);
    }

    private function normalizeTime(mixed $value): string
    {
        $str = (string) $value;

        return strlen($str) >= 5 ? substr($str, 0, 8) : $str;
    }
}
