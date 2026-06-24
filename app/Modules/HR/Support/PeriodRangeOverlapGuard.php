<?php

namespace App\Modules\HR\Support;

use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class PeriodRangeOverlapGuard
{
    public function assertNoOverlapForOutlet(
        int $outletId,
        string $periodStart,
        string $periodEnd,
        ?int $excludeAttendancePeriodId = null,
        ?int $excludePayrollPreparationPeriodId = null,
    ): void {
        $from = Carbon::parse($periodStart)->startOfDay();
        $until = Carbon::parse($periodEnd)->startOfDay();

        $attendanceQuery = AttendancePeriodLock::query()->where('outlet_id', $outletId);
        if ($excludeAttendancePeriodId !== null) {
            $attendanceQuery->where('id', '!=', $excludeAttendancePeriodId);
        }

        foreach ($attendanceQuery->get() as $period) {
            if ($this->rangesOverlap($from, $until, $period)) {
                throw ValidationException::withMessages([
                    'periodStart' => ['A period already overlaps this date range for this outlet.'],
                ]);
            }
        }

        $prepQuery = PayrollPreparationPeriod::query()->where('outlet_id', $outletId);
        if ($excludePayrollPreparationPeriodId !== null) {
            $prepQuery->where('id', '!=', $excludePayrollPreparationPeriodId);
        }

        foreach ($prepQuery->get() as $period) {
            if ($this->rangesOverlap($from, $until, $period)) {
                throw ValidationException::withMessages([
                    'periodStart' => ['A period already overlaps this date range for this outlet.'],
                ]);
            }
        }
    }

    private function rangesOverlap(Carbon $from, Carbon $until, AttendancePeriodLock|PayrollPreparationPeriod $period): bool
    {
        $existingFrom = $period->period_start->copy()->startOfDay();
        $existingUntil = $period->period_end->copy()->startOfDay();

        return $from->lte($existingUntil) && $existingFrom->lte($until);
    }
}
