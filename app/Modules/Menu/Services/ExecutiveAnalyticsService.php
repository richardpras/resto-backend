<?php

namespace App\Modules\Menu\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ExecutiveAnalyticsService
{
    public function __construct(
        private readonly FoodCostAnalyticsService $foodCostAnalytics,
        private readonly ProfitabilityAnalyticsService $profitabilityAnalytics,
        private readonly ProductionAnalyticsService $productionAnalytics,
        private readonly \App\Modules\Inventory\Services\InventoryAnalyticsService $inventoryAnalytics,
        private readonly MenuAnalyticsAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function getExecutiveSummary(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $fromDate = $fromDate ?? now()->startOfMonth()->toDateString();
        $toDate = $toDate ?? now()->toDateString();

        $foodCost = $this->foodCostAnalytics->getAverageFoodCost($outletId, $fromDate, $toDate);
        $profitSummary = $this->profitabilityAnalytics->getSummary($outletId);
        $inventorySummary = $this->inventoryAnalytics->getSummary($outletId);
        $productionEfficiency = $this->productionAnalytics->getProductionEfficiency($outletId, $fromDate, $toDate);
        $salesTrend = $this->resolveSalesTrend($outletId, $fromDate, $toDate);
        $orderStats = $this->resolveOrderStats($outletId, $fromDate, $toDate);
        $monthlyCogs = $this->resolveMonthlyCogs($outletId);

        $result = [
            'outletId' => $outletId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'averageFoodCostPercent' => $foodCost['averageFoodCostPercent'],
            'averageMarginPercent' => $profitSummary['averageMarginPercent'],
            'inventoryValue' => $inventorySummary['inventoryValue'],
            'dailyCogs' => $this->resolveDailyCogs($outletId, $toDate),
            'monthlyCogs' => $monthlyCogs,
            'productionEfficiency' => $productionEfficiency['productionEfficiencyPercent'],
            'inventoryTurnover' => $inventorySummary['turnover']['inventoryTurnover'],
            'salesTrend' => $salesTrend,
            'totalOrders' => $orderStats['totalOrders'],
            'totalRevenue' => $orderStats['totalRevenue'],
        ];

        $this->auditService->log('executive_kpi_generated', $outletId, $outletId, $actor, [
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ]);

        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function resolveSalesTrend(int $outletId, string $fromDate, string $toDate): array
    {
        return DB::table('orders')
            ->where('outlet_id', $outletId)
            ->where('payment_status', 'paid')
            ->whereDate('created_at', '>=', $fromDate)
            ->whereDate('created_at', '<=', $toDate)
            ->selectRaw('DATE(created_at) as sale_date')
            ->selectRaw('SUM(paid_total) as total_sales')
            ->selectRaw('COUNT(*) as order_count')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('sale_date')
            ->get()
            ->map(static fn ($row): array => [
                'date' => (string) $row->sale_date,
                'totalSales' => (float) $row->total_sales,
                'orderCount' => (int) $row->order_count,
            ])
            ->values()
            ->all();
    }

    /** @return array{totalOrders:int,totalRevenue:float} */
    private function resolveOrderStats(int $outletId, string $fromDate, string $toDate): array
    {
        $row = DB::table('orders')
            ->where('outlet_id', $outletId)
            ->where('payment_status', 'paid')
            ->whereDate('created_at', '>=', $fromDate)
            ->whereDate('created_at', '<=', $toDate)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('COALESCE(SUM(paid_total), 0) as total_revenue')
            ->first();

        return [
            'totalOrders' => (int) ($row->total_orders ?? 0),
            'totalRevenue' => (float) ($row->total_revenue ?? 0),
        ];
    }

    private function resolveDailyCogs(int $outletId, string $date): float
    {
        return (float) DB::table('stock_movements')
            ->where('outlet_id', $outletId)
            ->where('type', 'sale')
            ->whereDate('created_at', $date)
            ->sum('total_cost');
    }

    private function resolveMonthlyCogs(int $outletId): float
    {
        return (float) DB::table('stock_movements')
            ->where('outlet_id', $outletId)
            ->where('type', 'sale')
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('total_cost');
    }
}
