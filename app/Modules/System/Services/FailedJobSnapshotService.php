<?php

namespace App\Modules\System\Services;

use App\Models\Modules\System\Domain\FailedJobSnapshot;
use App\Modules\Notifications\Services\Adapters\FailedJobNotificationAdapter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FailedJobSnapshotService
{
    public function __construct(
        private readonly FailedJobMonitoringService $monitoringService,
        private readonly FailedJobNotificationAdapter $notificationAdapter,
    ) {}

    public function capture(?Carbon $date = null): FailedJobSnapshot
    {
        $snapshotDate = ($date ?? now())->toDateString();
        $summary = $this->monitoringService->aggregate();

        $previousTotal = (int) (FailedJobSnapshot::query()
            ->where('snapshot_date', '<', $snapshotDate)
            ->orderByDesc('snapshot_date')
            ->value('total_failures') ?? 0);

        $currentTotal = (int) $summary['failedJobs'];
        $resolvedFailures = max(0, $previousTotal - $currentTotal);

        $snapshot = FailedJobSnapshot::query()->updateOrCreate(
            ['snapshot_date' => $snapshotDate],
            [
                'total_failures' => $currentTotal,
                'critical_failures' => (int) $summary['criticalFailures'],
                'resolved_failures' => $resolvedFailures,
                'health_status' => (string) $summary['healthStatus'],
            ],
        );

        return $snapshot->fresh();
    }

    /**
     * @return Collection<int, FailedJobSnapshot>
     */
    public function trends(?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $start = ($startDate ?? now()->subDays(30))->toDateString();
        $end = ($endDate ?? now())->toDateString();

        return FailedJobSnapshot::query()
            ->whereBetween('snapshot_date', [$start, $end])
            ->orderBy('snapshot_date')
            ->get();
    }

    public function monitorAndNotify(): array
    {
        $summary = $this->monitoringService->aggregate();
        $outletId = $this->resolveNotificationOutletId();

        if ($outletId < 1) {
            return ['notified' => false, 'summary' => $summary];
        }

        $healthStatus = (string) $summary['healthStatus'];

        if ($healthStatus === FailedJobSeverityEngine::TIER_CRITICAL) {
            $this->notificationAdapter->notifyCriticalThreshold($outletId, $summary);
        } elseif (in_array($healthStatus, [FailedJobSeverityEngine::TIER_HIGH, FailedJobSeverityEngine::TIER_WARNING], true)) {
            $this->notificationAdapter->notifySpike($outletId, $summary);
        }

        return ['notified' => true, 'summary' => $summary, 'outletId' => $outletId];
    }

    private function resolveNotificationOutletId(): int
    {
        $id = DB::table('outlets')->where('status', 'active')->orderBy('id')->value('id');

        return $id !== null ? (int) $id : 0;
    }
}
