<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Models\Modules\Menu\Domain\AutomationSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AutomationSnapshotService
{
    public function __construct(
        private readonly MenuAutomationAuditService $auditService,
    ) {}

    public function createSnapshot(
        int $outletId,
        ?string $snapshotDate = null,
        ?User $actor = null,
        int $recommendationsGenerated = 0,
    ): AutomationSnapshot {
        $date = $snapshotDate ?? now()->toDateString();

        $existing = AutomationSnapshot::query()
            ->where('outlet_id', $outletId)
            ->whereDate('snapshot_date', $date)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($outletId, $date, $actor, $recommendationsGenerated): AutomationSnapshot {
            $locked = AutomationSnapshot::query()
                ->where('outlet_id', $outletId)
                ->whereDate('snapshot_date', $date)
                ->lockForUpdate()
                ->first();

            if ($locked !== null) {
                return $locked;
            }

            $alertsToday = AutomationAlert::query()
                ->where('outlet_id', $outletId)
                ->whereDate('triggered_at', $date)
                ->get();

            $resolvedToday = AutomationAlert::query()
                ->where('outlet_id', $outletId)
                ->where('status', AutomationAlert::STATUS_RESOLVED)
                ->whereDate('resolved_at', $date)
                ->count();

            $snapshot = AutomationSnapshot::query()->create([
                'snapshot_date' => $date,
                'outlet_id' => $outletId,
                'alerts_generated' => $alertsToday->count(),
                'critical_alerts' => $alertsToday->where('severity', 'critical')->count(),
                'warnings' => $alertsToday->where('severity', 'warning')->count(),
                'recommendations_generated' => $recommendationsGenerated,
                'resolved_alerts' => $resolvedToday,
            ]);

            $this->auditService->log('automation_snapshot_created', (int) $snapshot->id, $outletId, $actor, [
                'snapshotDate' => $date,
            ], entityType: 'automation_snapshot');

            return $snapshot;
        });
    }

    /** @return Collection<int, AutomationSnapshot> */
    public function getSnapshots(int $outletId, ?string $snapshotDate = null): Collection
    {
        $query = AutomationSnapshot::query()
            ->where('outlet_id', $outletId)
            ->orderByDesc('snapshot_date');

        if ($snapshotDate !== null) {
            $query->whereDate('snapshot_date', $snapshotDate);
        }

        return $query->get();
    }
}
