<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class RevenueForecastService
{
    public function __construct(
        private readonly DemandForecastService $demandForecast,
        private readonly RecipeCostService $recipeCostService,
        private readonly MenuProfitabilityService $profitabilityService,
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

        foreach ($demand['items'] as $row) {
            $menuItemId = (int) $row['menuItemId'];
            $qty = (float) $row['predictedQuantity'];
            $breakdown = $this->recipeCostService->calculateMenuCostBreakdown($menuItemId, $outletId, logCalculated: false);
            $price = (float) $breakdown['sellingPrice'];
            $cost = (float) $breakdown['finalTheoreticalCost'];
            $marginSnapshot = $this->profitabilityService->buildMarginSnapshot($price, $cost);
            $contributionMargin = (float) $marginSnapshot['contributionMargin'];

            $items[] = [
                'menuItemId' => (string) $menuItemId,
                'menuItemName' => $row['menuItemName'] ?? null,
                'forecastDate' => $targetDate,
                'predictedQuantity' => $qty,
                'sellingPrice' => $price,
                'contributionMargin' => $contributionMargin,
                'predictedRevenue' => round($qty * $price, 4),
                'predictedMargin' => round($qty * $contributionMargin, 4),
                'confidenceScore' => (float) $row['confidenceScore'],
                'horizons' => [
                    'dailyRevenue' => round($qty * $price, 4),
                    'weeklyRevenue' => round($qty * $price * 7, 4),
                    'monthlyRevenue' => round($qty * $price * 30, 4),
                ],
            ];
        }

        $this->auditService->log('revenue_forecast_generated', $outletId, $outletId, $actor, [
            'forecastDate' => $targetDate,
            'itemCount' => count($items),
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'forecastDate' => $targetDate,
            'items' => $items,
            'totals' => [
                'predictedRevenue' => round(array_sum(array_column($items, 'predictedRevenue')), 4),
                'predictedMargin' => round(array_sum(array_column($items, 'predictedMargin')), 4),
            ],
        ];
    }
}
