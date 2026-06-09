<?php

namespace App\Modules\Menu\Jobs;

use App\Modules\Menu\Services\AnalyticsSnapshotService;
use App\Modules\Menu\Services\MenuHardeningAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateDailyAnalyticsSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $outletId,
        public readonly ?string $snapshotDate = null,
    ) {}

    public function handle(
        AnalyticsSnapshotService $snapshotService,
        MenuHardeningAuditService $auditService,
    ): void {
        $auditService->log('queue_job_created', $this->outletId, $this->outletId, null, [
            'job' => self::class,
            'snapshotDate' => $this->snapshotDate,
        ], entityType: 'queue_job');

        $snapshotService->createDailySnapshot($this->outletId, $this->snapshotDate);
    }
}
