<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\DashboardSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DashboardSnapshotService
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardAuditService $auditService,
    ) {}

    public function createSnapshot(
        int $outletId,
        ?string $snapshotDate = null,
        ?User $actor = null,
    ): DashboardSnapshot {
        $date = $snapshotDate ?? now()->toDateString();

        $existing = DashboardSnapshot::query()
            ->where('outlet_id', $outletId)
            ->whereDate('snapshot_date', $date)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($outletId, $date, $actor): DashboardSnapshot {
            $locked = DashboardSnapshot::query()
                ->where('outlet_id', $outletId)
                ->whereDate('snapshot_date', $date)
                ->lockForUpdate()
                ->first();

            if ($locked !== null) {
                return $locked;
            }

            $summary = $this->dashboardService->getSummary($outletId, $actor);
            $kpis = $summary['kpis'];
            $engineering = $summary['engineering'];
            $optimization = $summary['optimization'];
            $automation = $summary['automation'];
            $inventory = $summary['inventory'];
            $health = $summary['health'];

            $snapshot = DashboardSnapshot::query()->create([
                'snapshot_date' => $date,
                'outlet_id' => $outletId,
                'total_revenue' => (float) $kpis['revenue'],
                'food_cost_percent' => (float) $kpis['foodCostPercent'],
                'average_margin_percent' => (float) $kpis['averageMarginPercent'],
                'star_count' => (int) $engineering['starCount'],
                'puzzle_count' => (int) $engineering['puzzleCount'],
                'plowhorse_count' => (int) $engineering['plowhorseCount'],
                'dog_count' => (int) $engineering['dogCount'],
                'active_alerts' => (int) $automation['openAlerts'],
                'critical_alerts' => (int) $automation['criticalAlerts'],
                'optimization_opportunities' => (int) $optimization['totalOpportunities'],
                'forecast_revenue' => (float) $kpis['forecastRevenue'],
                'forecast_margin' => (float) $kpis['forecastMargin'],
                'inventory_value' => (float) $inventory['inventoryValue'],
                'health_score' => (float) $health['score'],
            ]);

            $this->auditService->log('dashboard_snapshot_created', (int) $snapshot->id, $outletId, $actor, [
                'snapshotDate' => $date,
                'healthScore' => $health['score'],
            ]);

            return $snapshot;
        });
    }

    /** @return Collection<int, DashboardSnapshot> */
    public function getSnapshots(int $outletId, ?string $snapshotDate = null): Collection
    {
        $query = DashboardSnapshot::query()
            ->where('outlet_id', $outletId)
            ->orderByDesc('snapshot_date');

        if ($snapshotDate !== null) {
            $query->whereDate('snapshot_date', $snapshotDate);
        }

        return $query->get();
    }
}
