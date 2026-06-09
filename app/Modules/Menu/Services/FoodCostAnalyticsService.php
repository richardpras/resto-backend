<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class FoodCostAnalyticsService
{
    public function __construct(
        private readonly RecipeCostService $recipeCostService,
        private readonly MenuAnalyticsAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function getAverageFoodCost(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $totals = $this->resolvePeriodTotals($outletId, $fromDate, $toDate);
        $percent = $totals['revenue'] > 0
            ? round(($totals['cost'] / $totals['revenue']) * 100, 4)
            : 0.0;

        $this->auditService->log('food_cost_analytics_generated', $outletId, $outletId, $actor, [
            'averageFoodCostPercent' => $percent,
        ]);

        return [
            'outletId' => $outletId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'totalCost' => $totals['cost'],
            'totalRevenue' => $totals['revenue'],
            'averageFoodCostPercent' => $percent,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function getHighestFoodCostMenus(int $outletId, int $limit = 10, ?User $actor = null): array
    {
        return $this->rankMenusByFoodCost($outletId, $limit, descending: true, actor: $actor);
    }

    /** @return array<int,array<string,mixed>> */
    public function getLowestFoodCostMenus(int $outletId, int $limit = 10, ?User $actor = null): array
    {
        return $this->rankMenusByFoodCost($outletId, $limit, descending: false, actor: $actor);
    }

    /** @return array<int,array<string,mixed>> */
    public function getFoodCostTrend(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $query = DB::table('order_item_cost_snapshots as s')
            ->join('order_items as oi', 'oi.id', '=', 's.order_item_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('s.outlet_id', $outletId)
            ->where('o.payment_status', 'paid');

        if ($fromDate) {
            $query->whereDate('s.created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('s.created_at', '<=', $toDate);
        }

        $rows = $query
            ->selectRaw('DATE(s.created_at) as snapshot_date')
            ->selectRaw('SUM(s.total_cost) as total_cost')
            ->selectRaw('SUM(oi.line_total) as total_revenue')
            ->groupByRaw('DATE(s.created_at)')
            ->orderBy('snapshot_date')
            ->get()
            ->map(static function ($row): array {
                $revenue = (float) $row->total_revenue;
                $cost = (float) $row->total_cost;

                return [
                    'date' => (string) $row->snapshot_date,
                    'totalCost' => $cost,
                    'totalRevenue' => $revenue,
                    'foodCostPercent' => $revenue > 0 ? round(($cost / $revenue) * 100, 4) : 0.0,
                ];
            })
            ->values()
            ->all();

        $this->auditService->log('food_cost_analytics_generated', $outletId, $outletId, $actor, [
            'type' => 'trend',
            'pointCount' => count($rows),
        ]);

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function detectFoodCostIncrease(
        int $outletId,
        float $thresholdPercent = 5.0,
        ?User $actor = null,
    ): array {
        $alerts = [];
        $menuIds = $this->menuIdsForOutlet($outletId);

        foreach ($menuIds as $menuItemId) {
            $current = $this->recipeCostService->calculateTheoreticalFoodCost($menuItemId, $outletId);
            $historicalAvg = (float) DB::table('order_item_cost_snapshots as s')
                ->join('order_items as oi', 'oi.id', '=', 's.order_item_id')
                ->where('s.menu_item_id', $menuItemId)
                ->where('s.outlet_id', $outletId)
                ->where('oi.price', '>', 0)
                ->selectRaw('AVG(s.cost_per_unit / oi.price * 100) as avg_percent')
                ->value('avg_percent');

            if ($historicalAvg <= 0) {
                continue;
            }

            $currentPercent = (float) $current['theoreticalFoodCostPercent'];
            $increase = round($currentPercent - $historicalAvg, 4);

            if ($increase >= $thresholdPercent) {
                $alerts[] = [
                    'menuItemId' => (string) $menuItemId,
                    'menuItemName' => $current['menuItemId'] ? MenuItem::query()->find($menuItemId)?->name : null,
                    'historicalFoodCostPercent' => round($historicalAvg, 4),
                    'currentFoodCostPercent' => $currentPercent,
                    'increasePercent' => $increase,
                ];
            }
        }

        if ($alerts !== []) {
            $this->auditService->log('food_cost_alert_detected', $outletId, $outletId, $actor, [
                'alertCount' => count($alerts),
                'thresholdPercent' => $thresholdPercent,
            ]);
        }

        return $alerts;
    }

    /** @return array{cost:float,revenue:float} */
    private function resolvePeriodTotals(int $outletId, ?string $fromDate, ?string $toDate): array
    {
        $query = DB::table('order_item_cost_snapshots as s')
            ->join('order_items as oi', 'oi.id', '=', 's.order_item_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('s.outlet_id', $outletId)
            ->where('o.payment_status', 'paid');

        if ($fromDate) {
            $query->whereDate('s.created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('s.created_at', '<=', $toDate);
        }

        $row = $query
            ->selectRaw('COALESCE(SUM(s.total_cost), 0) as total_cost')
            ->selectRaw('COALESCE(SUM(oi.line_total), 0) as total_revenue')
            ->first();

        return [
            'cost' => (float) ($row->total_cost ?? 0),
            'revenue' => (float) ($row->total_revenue ?? 0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function rankMenusByFoodCost(int $outletId, int $limit, bool $descending, ?User $actor): array
    {
        $rows = [];
        foreach ($this->menuIdsForOutlet($outletId) as $menuItemId) {
            $foodCost = $this->recipeCostService->calculateTheoreticalFoodCost($menuItemId, $outletId);
            $rows[] = [
                'menuItemId' => (string) $menuItemId,
                'menuItemName' => MenuItem::query()->find($menuItemId)?->name,
                'theoreticalFoodCostPercent' => (float) $foodCost['theoreticalFoodCostPercent'],
                'finalTheoreticalCost' => (float) $foodCost['finalTheoreticalCost'],
                'sellingPrice' => (float) $foodCost['sellingPrice'],
            ];
        }

        usort($rows, static function (array $a, array $b) use ($descending): int {
            $cmp = $a['theoreticalFoodCostPercent'] <=> $b['theoreticalFoodCostPercent'];

            return $descending ? -$cmp : $cmp;
        });

        $this->auditService->log('food_cost_analytics_generated', $outletId, $outletId, $actor, [
            'type' => $descending ? 'highest' : 'lowest',
            'limit' => $limit,
        ]);

        return array_slice($rows, 0, max(1, $limit));
    }

    /** @return array<int,int> */
    private function menuIdsForOutlet(int $outletId): array
    {
        return MenuItem::query()
            ->where(function ($query) use ($outletId): void {
                $query->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
