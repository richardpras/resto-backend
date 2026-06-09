<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class MenuForecastingService
{
    public function __construct(
        private readonly DemandForecastService $demandForecast,
        private readonly RevenueForecastService $revenueForecast,
        private readonly IngredientForecastService $ingredientForecast,
        private readonly FoodCostForecastService $foodCostForecast,
        private readonly ProductionForecastService $productionForecast,
        private readonly StockRiskForecastService $stockRiskForecast,
        private readonly RecipeCostService $recipeCostService,
        private readonly MenuProfitabilityService $profitabilityService,
        private readonly MenuIntelligenceCacheService $cacheService,
    ) {}

    /** @return array<string,mixed> */
    public function getSummary(int $outletId, ?string $forecastDate = null, ?User $actor = null): array
    {
        $targetDate = $forecastDate ?? now()->addDay()->toDateString();

        return $this->cacheService->remember(
            $outletId,
            MenuIntelligenceCacheService::PREFIX_FORECAST,
            MenuIntelligenceCacheService::TTL_FORECAST,
            fn (): array => $this->buildSummary($outletId, $targetDate, $actor),
            md5($targetDate),
        );
    }

    /** @return array<string,mixed> */
    private function buildSummary(int $outletId, string $targetDate, ?User $actor = null): array
    {
        return [
            'outletId' => $outletId,
            'forecastDate' => $targetDate,
            'demand' => $this->demandForecast->forecastOutlet($outletId, $targetDate, $actor),
            'revenue' => $this->revenueForecast->forecastOutlet($outletId, $targetDate, $actor),
            'foodCost' => $this->foodCostForecast->forecastOutlet($outletId, $targetDate, $actor),
            'ingredients' => $this->ingredientForecast->forecastOutlet($outletId, $targetDate, $actor),
            'production' => $this->productionForecast->forecastOutlet($outletId, $targetDate, $actor),
            'stockRisk' => $this->stockRiskForecast->forecastOutlet($outletId, $targetDate, $actor),
        ];
    }

    /** @return array<string,mixed> */
    public function getMenuItemForecast(int $menuItemId, int $outletId, ?string $forecastDate = null): array
    {
        $targetDate = $forecastDate ?? now()->addDay()->toDateString();
        $demand = $this->demandForecast->forecastMenuItem($menuItemId, $outletId, $targetDate);
        $breakdown = $this->recipeCostService->calculateMenuCostBreakdown($menuItemId, $outletId, logCalculated: false);
        $price = (float) $breakdown['sellingPrice'];
        $cost = (float) $breakdown['finalTheoreticalCost'];
        $margin = $this->profitabilityService->buildMarginSnapshot($price, $cost);
        $qty = (float) $demand['predictedQuantity'];

        return [
            'menuItemId' => (string) $menuItemId,
            'forecastDate' => $targetDate,
            'demand' => $demand,
            'revenue' => [
                'predictedRevenue' => round($qty * $price, 4),
                'predictedMargin' => round($qty * (float) $margin['contributionMargin'], 4),
            ],
            'foodCost' => [
                'predictedFoodCost' => round($qty * $cost, 4),
                'predictedFoodCostPercent' => $price > 0 ? round(($cost / $price) * 100, 4) : 0,
            ],
        ];
    }
}
