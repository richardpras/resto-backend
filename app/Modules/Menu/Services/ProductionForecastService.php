<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class ProductionForecastService
{
    public function __construct(
        private readonly DemandForecastService $demandForecast,
        private readonly ProductionPlanningService $productionPlanning,
        private readonly StockRiskForecastService $stockRiskForecast,
        private readonly ForecastAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function forecastOutlet(
        int $outletId,
        ?string $forecastDate = null,
        ?User $actor = null,
    ): array {
        $targetDate = $forecastDate ?? now()->addDay()->toDateString();
        $demand = $this->demandForecast->forecastOutlet($outletId, $targetDate, $actor);

        $menuDemands = array_map(static fn (array $row): array => [
            'menuItemId' => (int) $row['menuItemId'],
            'quantity' => (float) $row['predictedQuantity'],
        ], $demand['items']);

        $plan = $this->productionPlanning->generateProductionPlan($outletId, $menuDemands, $actor);
        $stockRisks = $this->stockRiskForecast->forecastOutlet($outletId, $targetDate, $actor);

        $recommendations = [];
        foreach ($demand['items'] as $row) {
            $qty = (float) $row['predictedQuantity'];
            $recommendations[] = [
                'menuItemId' => $row['menuItemId'],
                'menuItemName' => $row['menuItemName'] ?? null,
                'prepQuantity' => round($qty * 1.1, 4),
                'batchQuantity' => round(max(1, $qty), 4),
                'purchasingQuantity' => 0,
            ];
        }

        foreach ($stockRisks['risks'] as $risk) {
            if (! in_array($risk['riskLevel'], ['critical', 'high'], true)) {
                continue;
            }
            $recommendations[] = [
                'type' => 'purchasing',
                'inventoryItemId' => $risk['inventoryItemId'],
                'ingredientName' => $risk['ingredientName'] ?? null,
                'purchasingQuantity' => round((float) $risk['avgDailyUsage'] * 7, 4),
                'shortageAlert' => true,
                'riskLevel' => $risk['riskLevel'],
            ];
        }

        $this->auditService->log('production_forecast_generated', $outletId, $outletId, $actor, [
            'forecastDate' => $targetDate,
            'recommendationCount' => count($recommendations),
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'forecastDate' => $targetDate,
            'productionPlan' => $plan,
            'recommendations' => $recommendations,
            'shortageAlerts' => array_values(array_filter(
                $stockRisks['risks'],
                static fn (array $r): bool => in_array($r['riskLevel'], ['critical', 'high'], true),
            )),
        ];
    }
}
