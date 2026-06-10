<?php

namespace App\Console\Commands;

use App\Modules\System\Services\FailedJobSnapshotService;
use Illuminate\Console\Command;

final class FailedJobMonitorCommand extends Command
{
    protected $signature = 'failed-jobs:monitor';

    protected $description = 'Check failed job thresholds and dispatch Notification Center alerts';

    public function handle(FailedJobSnapshotService $snapshotService): int
    {
        $result = $snapshotService->monitorAndNotify();
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        $this->info(sprintf(
            'Failed job monitor: status=%s total=%d notified=%s',
            (string) ($summary['healthStatus'] ?? 'healthy'),
            (int) ($summary['failedJobs'] ?? 0),
            ($result['notified'] ?? false) ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
