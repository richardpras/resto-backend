<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuRecipeCostSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ProductionAnalyticsService
{
    public function __construct(
        private readonly ProductionPlanningService $productionPlanningService,
        private readonly MenuAnalyticsAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function getSummary(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $data = [
            'outletId' => $outletId,
            'mostProduced' => $this->getMostProducedMenus($outletId, 5, $fromDate, $toDate),
            'leastProduced' => $this->getLeastProducedMenus($outletId, 5, $fromDate, $toDate),
            'productionEfficiency' => $this->getProductionEfficiency($outletId, $fromDate, $toDate),
            'yieldLoss' => $this->getYieldLossAnalysis($outletId),
        ];

        $this->auditService->log('production_analytics_generated', $outletId, $outletId, $actor, [
            'type' => 'summary',
        ]);

        return $data;
    }

    /** @return array<int,array<string,mixed>> */
    public function getMostProducedMenus(
        int $outletId,
        int $limit = 10,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        return $this->rankProducedMenus($outletId, $limit, descending: true, fromDate: $fromDate, toDate: $toDate);
    }

    /** @return array<int,array<string,mixed>> */
    public function getLeastProducedMenus(
        int $outletId,
        int $limit = 10,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        return $this->rankProducedMenus($outletId, $limit, descending: false, fromDate: $fromDate, toDate: $toDate);
    }

    /** @return array<int,array<string,mixed>> */
    public function getIngredientConsumptionTrend(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $query = DB::table('stock_movements')
            ->where('outlet_id', $outletId)
            ->where('type', 'sale');

        if ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        $rows = $query
            ->selectRaw('inventory_item_id')
            ->selectRaw('DATE(created_at) as movement_date')
            ->selectRaw('SUM(ABS(quantity)) as total_qty')
            ->selectRaw('SUM(COALESCE(total_cost, 0)) as total_cost')
            ->groupBy('inventory_item_id', DB::raw('DATE(created_at)'))
            ->orderBy('movement_date')
            ->get()
            ->map(static fn ($row): array => [
                'ingredientId' => (string) $row->inventory_item_id,
                'date' => (string) $row->movement_date,
                'quantity' => (float) $row->total_qty,
                'totalCost' => (float) $row->total_cost,
            ])
            ->values()
            ->all();

        $this->auditService->log('production_analytics_generated', $outletId, $outletId, $actor, [
            'type' => 'consumption_trend',
            'pointCount' => count($rows),
        ]);

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function getYieldLossAnalysis(int $outletId, ?User $actor = null): array
    {
        $menuIds = MenuItem::query()
            ->where(function ($query) use ($outletId): void {
                $query->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->pluck('id');

        $rows = MenuRecipeCostSetting::query()
            ->whereIn('menu_item_id', $menuIds)
            ->get()
            ->map(function (MenuRecipeCostSetting $setting): array {
                $yield = (float) $setting->yield_percent;
                $waste = (float) $setting->waste_percent;
                $yieldLoss = $yield < 100 ? round(100 - $yield, 4) : 0.0;

                return [
                    'menuItemId' => (string) $setting->menu_item_id,
                    'yieldPercent' => $yield,
                    'wastePercent' => $waste,
                    'yieldLossPercent' => $yieldLoss,
                    'combinedLossPercent' => round($yieldLoss + $waste, 4),
                ];
            })
            ->values()
            ->all();

        $this->auditService->log('production_analytics_generated', $outletId, $outletId, $actor, [
            'type' => 'yield_loss',
            'menuCount' => count($rows),
        ]);

        return $rows;
    }

    /** @return array<string,mixed> */
    public function getProductionEfficiency(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $salesQty = $this->productionPlanningService->deriveMenuDemandFromOrders($outletId, $fromDate, $toDate);
        $totalSales = array_sum(array_column($salesQty, 'quantity'));
        $forecastQty = array_sum(array_column(
            $this->productionPlanningService->generateIngredientDemand($outletId, $salesQty),
            'requiredQuantity',
        ));

        $efficiency = $forecastQty > 0
            ? round(min(100, ($totalSales / $forecastQty) * 100), 4)
            : ($totalSales > 0 ? 100.0 : 0.0);

        $this->auditService->log('production_analytics_generated', $outletId, $outletId, $actor, [
            'type' => 'efficiency',
            'efficiencyPercent' => $efficiency,
        ]);

        return [
            'outletId' => $outletId,
            'producedQuantity' => $forecastQty,
            'actualSalesQuantity' => $totalSales,
            'productionEfficiencyPercent' => $efficiency,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function rankProducedMenus(
        int $outletId,
        int $limit,
        bool $descending,
        ?string $fromDate,
        ?string $toDate,
    ): array {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.outlet_id', $outletId)
            ->where('orders.payment_status', 'paid')
            ->whereNotNull('order_items.item_id');

        if ($fromDate) {
            $query->whereDate('orders.created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('orders.created_at', '<=', $toDate);
        }

        $rows = $query
            ->selectRaw('order_items.item_id as menu_item_id')
            ->selectRaw('SUM(order_items.qty) as total_qty')
            ->groupBy('order_items.item_id')
            ->get()
            ->map(static fn ($row): array => [
                'menuItemId' => (string) $row->menu_item_id,
                'menuItemName' => MenuItem::query()->find((int) $row->menu_item_id)?->name,
                'quantitySold' => (float) $row->total_qty,
            ])
            ->all();

        usort($rows, static fn (array $a, array $b): int => $descending
            ? $b['quantitySold'] <=> $a['quantitySold']
            : $a['quantitySold'] <=> $b['quantitySold']);

        return array_slice($rows, 0, max(1, $limit));
    }
}
