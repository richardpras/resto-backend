<?php

namespace App\Modules\Menu\Jobs;

use App\Modules\Menu\Services\ForecastSnapshotService;
use App\Modules\Menu\Services\MenuHardeningAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateDailyForecastSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $outletId,
        public readonly ?string $snapshotDate = null,
        public readonly ?string $forecastDate = null,
    ) {}

    public function handle(
        ForecastSnapshotService $snapshotService,
        MenuHardeningAuditService $auditService,
    ): void {
        $auditService->log('queue_job_created', $this->outletId, $this->outletId, null, [
            'job' => self::class,
            'snapshotDate' => $this->snapshotDate,
            'forecastDate' => $this->forecastDate,
        ], entityType: 'queue_job');

        $snapshotService->createSnapshot($this->outletId, $this->snapshotDate, $this->forecastDate);
    }
}
