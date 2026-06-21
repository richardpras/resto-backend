<?php

namespace App\Console\Commands;

use App\Modules\Print\Services\PrintDispatchService;
use Illuminate\Console\Command;

final class ProcessPendingPrintJobsCommand extends Command
{
    protected $signature = 'print:process-pending
        {--outlet= : Optional outlet id filter}
        {--limit= : Maximum jobs to process in this run}';

    protected $description = 'Process pending and retryable print jobs (shared-hosting / cron fallback)';

    public function handle(PrintDispatchService $dispatchService): int
    {
        $outletOption = $this->option('outlet');
        $outletId = is_numeric($outletOption) ? (int) $outletOption : null;
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;

        $result = $dispatchService->processPendingBatch($outletId, $limit);

        $this->info(sprintf(
            'Print pending processor: mode=%s processed=%d skipped=%d',
            $dispatchService->mode(),
            (int) ($result['processed'] ?? 0),
            (int) ($result['skipped'] ?? 0),
        ));

        return self::SUCCESS;
    }
}
