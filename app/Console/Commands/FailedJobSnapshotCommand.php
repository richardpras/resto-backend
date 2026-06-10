<?php

namespace App\Console\Commands;

use App\Modules\System\Services\FailedJobSnapshotService;
use Illuminate\Console\Command;

final class FailedJobSnapshotCommand extends Command
{
    protected $signature = 'failed-jobs:snapshot';

    protected $description = 'Capture daily failed job health snapshot';

    public function handle(FailedJobSnapshotService $snapshotService): int
    {
        $snapshot = $snapshotService->capture();
        $this->info(sprintf(
            'Failed job snapshot %s: total=%d critical=%d status=%s',
            $snapshot->snapshot_date?->toDateString() ?? '-',
            (int) $snapshot->total_failures,
            (int) $snapshot->critical_failures,
            (string) $snapshot->health_status,
        ));

        return self::SUCCESS;
    }
}
