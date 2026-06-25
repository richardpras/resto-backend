<?php

namespace App\Modules\Menu\Services;

use App\Models\User;
use App\Modules\Inventory\Services\InventoryAnalyticsService;
use Carbon\CarbonImmutable;

final class MenuIntelligenceBundleService
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardSnapshotService $snapshotService,
        private readonly MenuEngineeringMatrixService $engineeringMatrix,
        private readonly ExecutiveAnalyticsService $executiveAnalytics,
        private readonly FoodCostAnalyticsService $foodCostAnalytics,
        private readonly ProfitabilityAnalyticsService $profitabilityAnalytics,
        private readonly InventoryAnalyticsService $inventoryAnalytics,
        private readonly PriceOptimizationService $priceOptimization,
        private readonly BundleRecommendationService $bundleService,
        private readonly IngredientOptimizationService $ingredientOptimization,
        private readonly YieldOptimizationService $yieldOptimization,
        private readonly MenuAutomationService $automationService,
        private readonly MenuForecastingService $forecastingService,
        private readonly MenuIntelligenceCacheService $cacheService,
    ) {}

    /** @return array<string,mixed> */
    public function getBundle(
        int $outletId,
        ?User $actor = null,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        [$fromDate, $toDate] = $this->resolveDateRange($fromDate, $toDate);

        return $this->cacheService->remember(
            $outletId,
            MenuIntelligenceCacheService::PREFIX_INTELLIGENCE_BUNDLE,
            MenuIntelligenceCacheService::TTL_DASHBOARD,
            fn (): array => $this->buildBundle($outletId, $actor, $fromDate, $toDate),
            md5($fromDate.'|'.$toDate),
        );
    }

    /** @return array{0:string,1:string} */
    private function resolveDateRange(?string $fromDate, ?string $toDate): array
    {
        $to = $toDate !== null && $toDate !== ''
            ? CarbonImmutable::parse($toDate)->toDateString()
            : now()->toDateString();
        $from = $fromDate !== null && $fromDate !== ''
            ? CarbonImmutable::parse($fromDate)->toDateString()
            : CarbonImmutable::parse($to)->subDays(29)->toDateString();

        return [$from, $to];
    }

    /** @return array<string,mixed> */
    private function buildBundle(int $outletId, ?User $actor, string $fromDate, string $toDate): array
    {
        $this->dashboardService->recordView($outletId, $actor);

        return [
            'summary' => $this->dashboardService->getSummary($outletId, $actor),
            'matrix' => $this->whenPermitted($actor, 'analytics.view', fn (): array => $this->engineeringMatrix->generateMatrix(
                $outletId,
                $fromDate,
                $toDate,
                $actor,
            )),
            'snapshots' => $this->snapshotService->getSnapshots($outletId),
            'executive' => $this->whenPermitted($actor, 'analytics.view', fn (): array => $this->executiveAnalytics->getExecutiveSummary(
                $outletId,
                $fromDate,
                $toDate,
                $actor,
            )),
            'foodCostTrend' => $this->whenPermitted($actor, 'analytics.view', fn (): array => $this->foodCostAnalytics->getFoodCostTrend(
                $outletId,
                $fromDate,
                $toDate,
                $actor,
            )),
            'marginTrend' => $this->whenPermitted($actor, 'analytics.view', fn (): array => $this->profitabilityAnalytics->getMarginTrend(
                $outletId,
                $fromDate,
                $toDate,
                $actor,
            )),
            'inventory' => $this->whenPermitted($actor, 'analytics.view', fn (): array => $this->inventoryAnalytics->getSummary($outletId, $actor)),
            'priceOpportunities' => $this->whenPermitted($actor, 'optimization.view', function () use ($outletId, $fromDate, $toDate, $actor): array {
                $result = $this->priceOptimization->analyzeOutlet($outletId, $fromDate, $toDate, $actor);

                return $result['opportunities'] ?? [];
            }),
            'bundleOpportunities' => $this->whenPermitted($actor, 'optimization.view', fn (): array => $this->bundleService->getTopBundles(
                $outletId,
                10,
                $fromDate,
                $toDate,
            )),
            'ingredientOpportunities' => $this->whenPermitted($actor, 'optimization.view', function () use ($outletId, $actor): array {
                $result = $this->ingredientOptimization->analyzeOutlet($outletId, $actor);

                return $result['opportunities'] ?? [];
            }),
            'yieldOpportunities' => $this->whenPermitted($actor, 'optimization.view', function () use ($outletId, $actor): array {
                $result = $this->yieldOptimization->analyzeOutlet($outletId, $actor);

                return $result['opportunities'] ?? [];
            }),
            'openAlerts' => $this->whenPermitted($actor, 'automation.view', fn (): array => $this->automationService->getOpenAlerts($outletId)->values()->all()),
            'criticalAlerts' => $this->whenPermitted($actor, 'automation.view', fn (): array => $this->automationService->getCriticalAlerts($outletId)->values()->all()),
            'resolvedAlerts' => $this->whenPermitted($actor, 'automation.view', fn (): array => $this->automationService->getAlertHistory($outletId)->values()->all()),
            ...$this->forecastSections($outletId, $actor),
        ];
    }

    /** @return array<string,mixed> */
    private function forecastSections(int $outletId, ?User $actor): array
    {
        if ($actor !== null && ! $actor->hasPermission('forecasting.view')) {
            return [
                'demandForecast' => null,
                'revenueForecast' => null,
                'productionForecast' => null,
                'stockRisk' => null,
            ];
        }

        $forecast = $this->forecastingService->getSummary($outletId, actor: $actor);

        return [
            'demandForecast' => $forecast['demand'] ?? [],
            'revenueForecast' => $forecast['revenue'] ?? [],
            'productionForecast' => $forecast['production'] ?? [],
            'stockRisk' => $forecast['stockRisk'] ?? [],
        ];
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T|null
     */
    private function whenPermitted(?User $actor, string $permission, callable $callback): mixed
    {
        if ($actor !== null && ! $actor->hasPermission($permission)) {
            return null;
        }

        return $callback();
    }
}
