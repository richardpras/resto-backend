<?php

namespace App\Jobs\Print;

use App\Modules\Print\Services\PrintQueueProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPrintJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120, 240];

    public function __construct(
        public readonly int $printJobId,
        public readonly int $outletId,
    ) {
        $this->onQueue((string) config('queue.routing.print_queue', 'print-jobs'));
    }

    public function uniqueId(): string
    {
        return 'print-job-'.$this->outletId.'-'.$this->printJobId;
    }

    public function handle(PrintQueueProcessingService $service): void
    {
        $service->processJob($this->printJobId, $this->outletId);
    }
}
