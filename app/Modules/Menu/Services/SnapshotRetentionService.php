<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\AutomationSnapshot;
use App\Models\Modules\Menu\Domain\ForecastSnapshot;
use App\Models\Modules\Menu\Domain\MenuAnalyticsSnapshot;
use App\Models\Modules\Menu\Domain\MenuEngineeringSnapshot;
use App\Models\Modules\Menu\Domain\MenuOptimizationSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SnapshotRetentionService
{
    public const RETENTION_MONTHS = 24;

    public function __construct(
        private readonly MenuHardeningAuditService $auditService,
    ) {}

    /** @return array<string,int> */
    public function archiveExpiredSnapshots(?int $outletId = null, ?User $actor = null): array
    {
        $cutoff = now()->subMonths(self::RETENTION_MONTHS)->toDateString();
        $archivedAt = now();

        $counts = [
            'analytics' => $this->archiveAnalytics($cutoff, $archivedAt, $outletId),
            'engineering' => $this->archiveEngineering($cutoff, $archivedAt, $outletId),
            'optimization' => $this->archiveOptimization($cutoff, $archivedAt, $outletId),
            'automation' => $this->archiveAutomation($cutoff, $archivedAt, $outletId),
            'forecast' => $this->archiveForecast($cutoff, $archivedAt, $outletId),
        ];

        $total = array_sum($counts);
        if ($total > 0) {
            $this->auditService->log('retention_job_executed', $outletId ?? 0, $outletId, $actor, [
                'cutoffDate' => $cutoff,
                'archivedCounts' => $counts,
            ], entityType: 'snapshot_retention');
        }

        return $counts;
    }

    private function archiveAnalytics(string $cutoff, \Illuminate\Support\Carbon $archivedAt, ?int $outletId): int
    {
        $count = 0;
        $query = MenuAnalyticsSnapshot::query()->whereDate('snapshot_date', '<', $cutoff);
        if ($outletId !== null) {
            $query->where('outlet_id', $outletId);
        }

        $query->orderBy('id')->chunkById(100, function ($rows) use (&$count, $archivedAt): void {
            foreach ($rows as $row) {
                $exists = DB::table('analytics_snapshot_archives')
                    ->where('source_snapshot_id', $row->id)
                    ->exists();
                if ($exists) {
                    continue;
                }

                DB::table('analytics_snapshot_archives')->insert([
                    'source_snapshot_id' => $row->id,
                    'snapshot_date' => $row->snapshot_date,
                    'outlet_id' => $row->outlet_id,
                    'average_food_cost_percent' => $row->average_food_cost_percent,
                    'average_margin_percent' => $row->average_margin_percent,
                    'inventory_value' => $row->inventory_value,
                    'daily_cogs' => $row->daily_cogs,
                    'production_efficiency_percent' => $row->production_efficiency_percent,
                    'total_sales' => $row->total_sales,
                    'total_orders' => $row->total_orders,
                    'archived_at' => $archivedAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->auditService->log('snapshot_archived', (int) $row->id, (int) $row->outlet_id, null, [
                    'type' => 'analytics',
                    'snapshotDate' => $row->snapshot_date?->toDateString(),
                ], entityType: 'menu_analytics_snapshot');

                $count++;
            }
        });

        return $count;
    }

    private function archiveEngineering(string $cutoff, \Illuminate\Support\Carbon $archivedAt, ?int $outletId): int
    {
        $count = 0;
        $query = MenuEngineeringSnapshot::query()->whereDate('snapshot_date', '<', $cutoff);
        if ($outletId !== null) {
            $query->where('outlet_id', $outletId);
        }

        $query->orderBy('id')->chunkById(100, function ($rows) use (&$count, $archivedAt): void {
            foreach ($rows as $row) {
                if (DB::table('engineering_snapshot_archives')->where('source_snapshot_id', $row->id)->exists()) {
                    continue;
                }

                DB::table('engineering_snapshot_archives')->insert([
                    'source_snapshot_id' => $row->id,
                    'snapshot_date' => $row->snapshot_date,
                    'outlet_id' => $row->outlet_id,
                    'menu_item_id' => $row->menu_item_id,
                    'quantity_sold' => $row->quantity_sold,
                    'popularity_percent' => $row->popularity_percent,
                    'contribution_margin' => $row->contribution_margin,
                    'margin_percent' => $row->margin_percent,
                    'classification' => $row->classification,
                    'archived_at' => $archivedAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->auditService->log('snapshot_archived', (int) $row->id, (int) $row->outlet_id, null, [
                    'type' => 'engineering',
                ], entityType: 'menu_engineering_snapshot');

                $count++;
            }
        });

        return $count;
    }

    private function archiveOptimization(string $cutoff, \Illuminate\Support\Carbon $archivedAt, ?int $outletId): int
    {
        $count = 0;
        $query = MenuOptimizationSnapshot::query()->whereDate('snapshot_date', '<', $cutoff);
        if ($outletId !== null) {
            $query->where('outlet_id', $outletId);
        }

        $query->orderBy('id')->chunkById(100, function ($rows) use (&$count, $archivedAt): void {
            foreach ($rows as $row) {
                if (DB::table('optimization_snapshot_archives')->where('source_snapshot_id', $row->id)->exists()) {
                    continue;
                }

                DB::table('optimization_snapshot_archives')->insert([
                    'source_snapshot_id' => $row->id,
                    'snapshot_date' => $row->snapshot_date,
                    'outlet_id' => $row->outlet_id,
                    'menu_item_id' => $row->menu_item_id,
                    'recommendation_type' => $row->recommendation_type,
                    'recommendation_json' => is_string($row->recommendation_json)
                        ? $row->recommendation_json
                        : json_encode($row->recommendation_json),
                    'projected_margin_percent' => $row->projected_margin_percent,
                    'projected_profit_increase' => $row->projected_profit_increase,
                    'archived_at' => $archivedAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->auditService->log('snapshot_archived', (int) $row->id, (int) $row->outlet_id, null, [
                    'type' => 'optimization',
                ], entityType: 'menu_optimization_snapshot');

                $count++;
            }
        });

        return $count;
    }

    private function archiveAutomation(string $cutoff, \Illuminate\Support\Carbon $archivedAt, ?int $outletId): int
    {
        $count = 0;
        $query = AutomationSnapshot::query()->whereDate('snapshot_date', '<', $cutoff);
        if ($outletId !== null) {
            $query->where('outlet_id', $outletId);
        }

        $query->orderBy('id')->chunkById(100, function ($rows) use (&$count, $archivedAt): void {
            foreach ($rows as $row) {
                if (DB::table('automation_snapshot_archives')->where('source_snapshot_id', $row->id)->exists()) {
                    continue;
                }

                DB::table('automation_snapshot_archives')->insert([
                    'source_snapshot_id' => $row->id,
                    'snapshot_date' => $row->snapshot_date,
                    'outlet_id' => $row->outlet_id,
                    'alerts_generated' => $row->alerts_generated,
                    'critical_alerts' => $row->critical_alerts,
                    'warnings' => $row->warnings,
                    'recommendations_generated' => $row->recommendations_generated,
                    'resolved_alerts' => $row->resolved_alerts,
                    'archived_at' => $archivedAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->auditService->log('snapshot_archived', (int) $row->id, (int) $row->outlet_id, null, [
                    'type' => 'automation',
                ], entityType: 'automation_snapshot');

                $count++;
            }
        });

        return $count;
    }

    private function archiveForecast(string $cutoff, \Illuminate\Support\Carbon $archivedAt, ?int $outletId): int
    {
        $count = 0;
        $query = ForecastSnapshot::query()->whereDate('snapshot_date', '<', $cutoff);
        if ($outletId !== null) {
            $query->where('outlet_id', $outletId);
        }

        $query->orderBy('id')->chunkById(100, function ($rows) use (&$count, $archivedAt): void {
            foreach ($rows as $row) {
                if (DB::table('forecast_snapshot_archives')->where('source_snapshot_id', $row->id)->exists()) {
                    continue;
                }

                DB::table('forecast_snapshot_archives')->insert([
                    'source_snapshot_id' => $row->id,
                    'snapshot_date' => $row->snapshot_date,
                    'forecast_date' => $row->forecast_date,
                    'outlet_id' => $row->outlet_id,
                    'menu_item_id' => $row->menu_item_id,
                    'inventory_item_id' => $row->inventory_item_id,
                    'forecast_type' => $row->forecast_type,
                    'predicted_quantity' => $row->predicted_quantity,
                    'predicted_revenue' => $row->predicted_revenue,
                    'predicted_margin' => $row->predicted_margin,
                    'confidence_score' => $row->confidence_score,
                    'metadata_json' => $row->metadata_json !== null ? json_encode($row->metadata_json) : null,
                    'archived_at' => $archivedAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->auditService->log('snapshot_archived', (int) $row->id, (int) $row->outlet_id, null, [
                    'type' => 'forecast',
                ], entityType: 'forecast_snapshot');

                $count++;
            }
        });

        return $count;
    }
}
