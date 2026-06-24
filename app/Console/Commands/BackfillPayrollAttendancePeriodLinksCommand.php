<?php

namespace App\Console\Commands;

use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use Illuminate\Console\Command;

class BackfillPayrollAttendancePeriodLinksCommand extends Command
{
    protected $signature = 'hr:backfill-payroll-attendance-period-links {--dry-run : Report without writing}';

    protected $description = 'Link attendance_period_locks to payroll_preparation_periods by exact outlet and date range';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $linked = 0;
        $skippedAlreadyLinked = 0;
        $unmatchedAttendance = 0;
        $unmatchedPrep = 0;

        $preparationByKey = PayrollPreparationPeriod::query()
            ->get()
            ->keyBy(fn (PayrollPreparationPeriod $period) => $this->rangeKey(
                (int) $period->outlet_id,
                $period->period_start->toDateString(),
                $period->period_end->toDateString(),
            ));

        $matchedPrepIds = [];

        foreach (AttendancePeriodLock::query()->get() as $attendancePeriod) {
            if ($attendancePeriod->payroll_preparation_period_id !== null) {
                $skippedAlreadyLinked++;
                $matchedPrepIds[(int) $attendancePeriod->payroll_preparation_period_id] = true;

                continue;
            }

            $key = $this->rangeKey(
                (int) $attendancePeriod->outlet_id,
                $attendancePeriod->period_start->toDateString(),
                $attendancePeriod->period_end->toDateString(),
            );

            $prep = $preparationByKey->get($key);
            if ($prep === null) {
                $unmatchedAttendance++;
                $this->warn(sprintf(
                    'Unmatched attendance period #%d (%s)',
                    $attendancePeriod->id,
                    $key,
                ));

                continue;
            }

            if (isset($matchedPrepIds[(int) $prep->id])) {
                $this->warn(sprintf(
                    'Duplicate attendance match for prep #%d (%s)',
                    $prep->id,
                    $key,
                ));

                continue;
            }

            if (! $dryRun) {
                $attendancePeriod->update(['payroll_preparation_period_id' => $prep->id]);
            }

            $matchedPrepIds[(int) $prep->id] = true;
            $linked++;
        }

        foreach ($preparationByKey as $key => $prep) {
            if (! isset($matchedPrepIds[(int) $prep->id])) {
                $unmatchedPrep++;
                $this->warn(sprintf('Unmatched payroll preparation period #%d (%s)', $prep->id, $key));
            }
        }

        $this->info(sprintf(
            '%sLinked %d, already linked %d, unmatched attendance %d, unmatched prep %d.',
            $dryRun ? '[dry-run] Would have ' : '',
            $linked,
            $skippedAlreadyLinked,
            $unmatchedAttendance,
            $unmatchedPrep,
        ));

        return self::SUCCESS;
    }

    private function rangeKey(int $outletId, string $periodStart, string $periodEnd): string
    {
        return $outletId.'|'.$periodStart.'|'.$periodEnd;
    }
}
