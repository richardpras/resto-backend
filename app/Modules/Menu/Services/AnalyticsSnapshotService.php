<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuAnalyticsSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AnalyticsSnapshotService
{
    public function __construct(
        private readonly ExecutiveAnalyticsService $executiveAnalytics,
        private readonly MenuAnalyticsAuditService $auditService,
    ) {}

    public function createDailySnapshot(
        int $outletId,
        ?string $snapshotDate = null,
        ?User $actor = null,
    ): MenuAnalyticsSnapshot {
        $date = $snapshotDate ?? now()->toDateString();

        $existing = MenuAnalyticsSnapshot::query()
            ->where('outlet_id', $outletId)
            ->whereDate('snapshot_date', $date)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($outletId, $date, $actor): MenuAnalyticsSnapshot {
            $locked = MenuAnalyticsSnapshot::query()
                ->where('outlet_id', $outletId)
                ->whereDate('snapshot_date', $date)
                ->lockForUpdate()
                ->first();

            if ($locked !== null) {
                return $locked;
            }

            $kpi = $this->executiveAnalytics->getExecutiveSummary($outletId, $date, $date, $actor);

            $snapshot = MenuAnalyticsSnapshot::query()->create([
                'snapshot_date' => $date,
                'outlet_id' => $outletId,
                'average_food_cost_percent' => $kpi['averageFoodCostPercent'],
                'average_margin_percent' => $kpi['averageMarginPercent'],
                'inventory_value' => $kpi['inventoryValue'],
                'daily_cogs' => $kpi['dailyCogs'],
                'production_efficiency_percent' => $kpi['productionEfficiency'],
                'total_sales' => $kpi['totalRevenue'],
                'total_orders' => $kpi['totalOrders'],
            ]);

            $this->auditService->log('analytics_snapshot_created', (int) $snapshot->id, $outletId, $actor, [
                'snapshotDate' => $date,
                'outletId' => $outletId,
            ], entityType: 'menu_analytics_snapshot');

            return $snapshot;
        });
    }
}
