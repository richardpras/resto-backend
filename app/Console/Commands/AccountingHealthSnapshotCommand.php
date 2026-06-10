<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Services\AccountingHealthSnapshotService;
use Illuminate\Console\Command;

class AccountingHealthSnapshotCommand extends Command
{
    protected $signature = 'accounting:health-snapshot {--outletId=}';

    protected $description = 'Capture daily accounting health snapshots per outlet';

    public function handle(AccountingHealthSnapshotService $snapshotService): int
    {
        $outletId = $this->option('outletId');
        if (is_numeric($outletId) && (int) $outletId > 0) {
            $snapshot = $snapshotService->captureForOutlet((int) $outletId);
            $this->info(sprintf(
                'Snapshot saved for outlet %d on %s (severity: %s).',
                (int) $outletId,
                $snapshot->snapshot_date->toDateString(),
                $snapshot->severity,
            ));

            return self::SUCCESS;
        }

        $snapshots = $snapshotService->captureAllOutlets();
        $this->info(sprintf('Captured %d outlet accounting health snapshot(s).', $snapshots->count()));

        return self::SUCCESS;
    }
}
