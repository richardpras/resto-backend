<?php

namespace App\Modules\Menu\Services;

use App\Models\User;
use App\Modules\Inventory\Services\InventoryAnalyticsService;

final class DashboardService
{
    private const FOOD_COST_THRESHOLD = 40.0;

    public function __construct(
        private readonly ExecutiveAnalyticsService $executiveAnalytics,
        private readonly MenuEngineeringMatrixService $engineeringMatrix,
        private readonly PriceOptimizationService $priceOptimization,
        private readonly BundleRecommendationService $bundleService,
        private readonly IngredientOptimizationService $ingredientOptimization,
        private readonly YieldOptimizationService $yieldOptimization,
        private readonly MenuAutomationService $automationService,
        private readonly MenuForecastingService $forecastingService,
        private readonly InventoryAnalyticsService $inventoryAnalytics,
        private readonly ProfitabilityAnalyticsService $profitabilityAnalytics,
        private readonly DashboardAuditService $auditService,
        private readonly MenuIntelligenceCacheService $cacheService,
    ) {}

    /** @return array<string,mixed> */
    public function getSummary(int $outletId, ?User $actor = null): array
    {
        return $this->cacheService->remember(
            $outletId,
            MenuIntelligenceCacheService::PREFIX_DASHBOARD,
            MenuIntelligenceCacheService::TTL_DASHBOARD,
            fn (): array => $this->buildSummary($outletId, $actor),
        );
    }

    /** @return array<string,mixed> */
    private function buildSummary(int $outletId, ?User $actor = null): array
    {
        $summary = [
            'outletId' => $outletId,
            'generatedAt' => now()->toIso8601String(),
            'kpis' => $this->getKpis($outletId, $actor),
            'engineering' => $this->getEngineering($outletId, $actor),
            'optimization' => $this->getOptimization($outletId, $actor),
            'automation' => $this->getAutomation($outletId),
            'forecasting' => $this->getForecasting($outletId, $actor),
            'inventory' => $this->getInventory($outletId, $actor),
            'health' => $this->getHealth($outletId, $actor),
        ];

        $this->auditService->log('dashboard_generated', $outletId, $outletId, $actor, [
            'healthScore' => $summary['health']['score'],
        ], entityType: 'outlet');

        return $summary;
    }

    /** @return array<string,mixed> */
    public function getKpis(int $outletId, ?User $actor = null): array
    {
        $executive = $this->executiveAnalytics->getExecutiveSummary($outletId, actor: $actor);
        $forecast = $this->forecastingService->getSummary($outletId, actor: $actor);

        return [
            'revenue' => (float) $executive['totalRevenue'],
            'foodCostPercent' => (float) $executive['averageFoodCostPercent'],
            'averageMarginPercent' => (float) $executive['averageMarginPercent'],
            'forecastRevenue' => (float) ($forecast['revenue']['totals']['predictedRevenue'] ?? 0),
            'forecastMargin' => (float) ($forecast['revenue']['totals']['predictedMargin'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    public function getEngineering(int $outletId, ?User $actor = null): array
    {
        $matrix = $this->engineeringMatrix->generateMatrix($outletId, actor: $actor);
        $summary = $matrix['summary'] ?? [];

        return [
            'starCount' => (int) ($summary[MenuEngineeringMatrixService::STAR] ?? 0),
            'puzzleCount' => (int) ($summary[MenuEngineeringMatrixService::PUZZLE] ?? 0),
            'plowhorseCount' => (int) ($summary[MenuEngineeringMatrixService::PLOWHORSE] ?? 0),
            'dogCount' => (int) ($summary[MenuEngineeringMatrixService::DOG] ?? 0),
            'benchmarks' => $matrix['benchmarks'] ?? [],
        ];
    }

    /** @return array<string,mixed> */
    public function getOptimization(int $outletId, ?User $actor = null): array
    {
        $pricing = $this->priceOptimization->analyzeOutlet($outletId, actor: $actor);
        $bundles = $this->bundleService->getTopBundles($outletId);
        $ingredients = $this->ingredientOptimization->analyzeOutlet($outletId, $actor);
        $yield = $this->yieldOptimization->analyzeOutlet($outletId, $actor);

        $priceCount = count($pricing['opportunities'] ?? []);
        $bundleCount = count($bundles);
        $ingredientCount = count($ingredients['opportunities'] ?? []);
        $yieldCount = count($yield['opportunities'] ?? []);

        return [
            'priceOpportunities' => $priceCount,
            'ingredientOpportunities' => $ingredientCount,
            'yieldOpportunities' => $yieldCount,
            'bundleOpportunities' => $bundleCount,
            'totalOpportunities' => $priceCount + $bundleCount + $ingredientCount + $yieldCount,
        ];
    }

    /** @return array<string,mixed> */
    public function getAutomation(int $outletId): array
    {
        $auto = $this->automationService->getDashboardSummary($outletId);

        return [
            'openAlerts' => (int) $auto['openAlerts'],
            'criticalAlerts' => (int) $auto['criticalAlerts'],
            'resolvedToday' => (int) $auto['resolvedToday'],
        ];
    }

    /** @return array<string,mixed> */
    public function getForecasting(int $outletId, ?User $actor = null): array
    {
        $forecast = $this->forecastingService->getSummary($outletId, actor: $actor);

        return [
            'demand' => [
                'itemCount' => count($forecast['demand']['items'] ?? []),
                'peakPeriods' => $forecast['demand']['peakPeriods'] ?? [],
            ],
            'revenue' => $forecast['revenue']['totals'] ?? [],
            'stockRisks' => count($forecast['stockRisk']['risks'] ?? []),
            'criticalStockRisks' => count(array_filter(
                $forecast['stockRisk']['risks'] ?? [],
                static fn (array $r): bool => ($r['riskLevel'] ?? '') === 'critical',
            )),
        ];
    }

    /** @return array<string,mixed> */
    public function getInventory(int $outletId, ?User $actor = null): array
    {
        $inventory = $this->inventoryAnalytics->getSummary($outletId, $actor);
        $forecast = $this->forecastingService->getSummary($outletId, actor: $actor);
        $criticalRisks = count(array_filter(
            $forecast['stockRisk']['risks'] ?? [],
            static fn (array $r): bool => in_array($r['riskLevel'] ?? '', ['critical', 'high'], true),
        ));

        return [
            'inventoryValue' => (float) $inventory['inventoryValue'],
            'deadStockCount' => count($inventory['deadStock'] ?? []),
            'criticalStockRisks' => $criticalRisks,
        ];
    }

    /** @return array<string,mixed> */
    public function getHealth(int $outletId, ?User $actor = null): array
    {
        $kpis = $this->getKpis($outletId, $actor);
        $engineering = $this->getEngineering($outletId, $actor);
        $automation = $this->getAutomation($outletId);
        $inventory = $this->getInventory($outletId, $actor);
        $marginErosion = $this->profitabilityAnalytics->detectMarginErosion($outletId, 5.0, $actor);

        $score = 100.0;
        $score -= ((int) $automation['criticalAlerts']) * 10;
        $score -= ((int) $engineering['dogCount']) * 2;
        $score -= ((int) $inventory['criticalStockRisks']) * 5;

        if ((float) $kpis['foodCostPercent'] > self::FOOD_COST_THRESHOLD) {
            $score -= 10;
        }
        if ($marginErosion !== []) {
            $score -= 10;
        }

        $score = max(0.0, min(100.0, round($score, 2)));
        $band = $this->healthBand($score);

        $this->auditService->log('health_score_generated', $outletId, $outletId, $actor, [
            'score' => $score,
            'band' => $band,
        ], entityType: 'outlet');

        return [
            'score' => $score,
            'band' => $band,
            'penalties' => [
                'criticalAlerts' => (int) $automation['criticalAlerts'],
                'dogItems' => (int) $engineering['dogCount'],
                'criticalStockRisks' => (int) $inventory['criticalStockRisks'],
                'foodCostAboveThreshold' => (float) $kpis['foodCostPercent'] > self::FOOD_COST_THRESHOLD,
                'marginErosionDetected' => $marginErosion !== [],
            ],
        ];
    }

    public function recordView(int $outletId, ?User $actor = null): void
    {
        $this->auditService->log('dashboard_viewed', $outletId, $outletId, $actor, [], entityType: 'outlet');
    }

    private function healthBand(float $score): string
    {
        if ($score >= 85) {
            return 'excellent';
        }
        if ($score >= 70) {
            return 'good';
        }
        if ($score >= 50) {
            return 'warning';
        }

        return 'critical';
    }
}
