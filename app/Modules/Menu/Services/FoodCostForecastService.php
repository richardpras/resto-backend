<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class FoodCostForecastService
{
    public function __construct(
        private readonly DemandForecastService $demandForecast,
        private readonly RecipeCostService $recipeCostService,
        private readonly ForecastAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function forecastOutlet(
        int $outletId,
        ?string $forecastDate = null,
        ?User $actor = null,
    ): array {
        $targetDate = $forecastDate ?? now()->addDay()->toDateString();
        $demand = $this->demandForecast->forecastOutlet($outletId, $targetDate);
        $items = [];
        $totalCost = 0.0;
        $totalRevenue = 0.0;

        foreach ($demand['items'] as $row) {
            $menuItemId = (int) $row['menuItemId'];
            $qty = (float) $row['predictedQuantity'];
            $breakdown = $this->recipeCostService->calculateMenuCostBreakdown($menuItemId, $outletId, logCalculated: false);
            $unitCost = (float) $breakdown['finalTheoreticalCost'];
            $price = (float) $breakdown['sellingPrice'];
            $predictedCost = round($qty * $unitCost, 4);
            $predictedRevenue = round($qty * $price, 4);
            $foodCostPercent = $predictedRevenue > 0 ? round(($predictedCost / $predictedRevenue) * 100, 4) : 0.0;

            $totalCost += $predictedCost;
            $totalRevenue += $predictedRevenue;

            $items[] = [
                'menuItemId' => (string) $menuItemId,
                'menuItemName' => $row['menuItemName'] ?? null,
                'forecastDate' => $targetDate,
                'predictedQuantity' => $qty,
                'unitCost' => $unitCost,
                'predictedFoodCost' => $predictedCost,
                'predictedRevenue' => $predictedRevenue,
                'predictedFoodCostPercent' => $foodCostPercent,
                'confidenceScore' => (float) $row['confidenceScore'],
            ];
        }

        $this->auditService->log('food_cost_forecast_generated', $outletId, $outletId, $actor, [
            'forecastDate' => $targetDate,
            'averageFoodCostPercent' => $totalRevenue > 0 ? round(($totalCost / $totalRevenue) * 100, 4) : 0,
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'forecastDate' => $targetDate,
            'items' => $items,
            'totals' => [
                'predictedFoodCost' => round($totalCost, 4),
                'predictedRevenue' => round($totalRevenue, 4),
                'predictedFoodCostPercent' => $totalRevenue > 0 ? round(($totalCost / $totalRevenue) * 100, 4) : 0.0,
            ],
        ];
    }
}
