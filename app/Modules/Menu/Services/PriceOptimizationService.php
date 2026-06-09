<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class PriceOptimizationService
{
    private const TARGET_MARGIN_PERCENT = 25.0;

    private const PLOWHORSE_INCREASE_PERCENT = 8.0;

    public function __construct(
        private readonly RecipeCostService $recipeCostService,
        private readonly MenuProfitabilityService $profitabilityService,
        private readonly MenuPopularityService $popularityService,
        private readonly MenuEngineeringMatrixService $matrixService,
        private readonly MenuOptimizationAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function analyzeOutlet(int $outletId, ?string $fromDate = null, ?string $toDate = null, ?User $actor = null): array
    {
        $matrix = $this->matrixService->generateMatrix($outletId, $fromDate, $toDate);
        $opportunities = [];

        foreach ($matrix['items'] as $item) {
            $analysis = $this->analyzeMenuItem(
                (int) $item['menuItemId'],
                $outletId,
                (string) $item['classification'],
                $fromDate,
                $toDate,
            );
            if ($analysis['hasOpportunity']) {
                $opportunities[] = $analysis;
            }
        }

        $this->auditService->log('price_optimization_generated', $outletId, $outletId, $actor, [
            'opportunityCount' => count($opportunities),
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'opportunities' => $opportunities,
        ];
    }

    /** @return array<string,mixed> */
    public function analyzeMenuItem(
        int $menuItemId,
        int $outletId,
        ?string $classification = null,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        $breakdown = $this->recipeCostService->calculateMenuCostBreakdown($menuItemId, $outletId, logCalculated: false);
        $currentPrice = (float) $breakdown['sellingPrice'];
        $currentCost = (float) $breakdown['finalTheoreticalCost'];
        $currentSnapshot = $this->profitabilityService->buildMarginSnapshot($currentPrice, $currentCost);
        $currentMarginPercent = (float) $currentSnapshot['marginPercent'];
        $foodCostPercent = $currentPrice > 0 ? round(($currentCost / $currentPrice) * 100, 4) : 0.0;

        if ($classification === null) {
            $matrixItem = collect($this->matrixService->generateMatrix($outletId, $fromDate, $toDate)['items'])
                ->firstWhere('menuItemId', (string) $menuItemId);
            $classification = (string) ($matrixItem['classification'] ?? MenuEngineeringMatrixService::DOG);
        }

        $suggestedPrice = $this->suggestPrice($currentPrice, $currentCost, $classification, $currentMarginPercent);
        $proposedSnapshot = $this->profitabilityService->buildMarginSnapshot($suggestedPrice, $currentCost);
        $monthlyQty = $this->estimateMonthlyQuantity($menuItemId, $outletId, $fromDate, $toDate);
        $marginDelta = (float) $proposedSnapshot['margin'] - (float) $currentSnapshot['margin'];
        $projectedMonthlyProfit = round($marginDelta * $monthlyQty, 4);

        $direction = $suggestedPrice > $currentPrice ? 'increase' : ($suggestedPrice < $currentPrice ? 'decrease' : 'maintain');

        return [
            'menuItemId' => (string) $menuItemId,
            'menuItemName' => $breakdown['menuItemName'],
            'classification' => $classification,
            'currentPrice' => $currentPrice,
            'currentCost' => $currentCost,
            'foodCostPercent' => $foodCostPercent,
            'contributionMargin' => (float) $currentSnapshot['contributionMargin'],
            'currentMarginPercent' => $currentMarginPercent,
            'popularityPercent' => $this->popularityService->calculatePopularityPercent($menuItemId, $outletId, $fromDate, $toDate),
            'suggestedPrice' => $suggestedPrice,
            'suggestedDirection' => $direction,
            'projectedMarginPercent' => (float) $proposedSnapshot['marginPercent'],
            'projectedMonthlyProfitIncrease' => $projectedMonthlyProfit,
            'hasOpportunity' => $direction !== 'maintain',
        ];
    }

    private function suggestPrice(
        float $currentPrice,
        float $currentCost,
        string $classification,
        float $currentMarginPercent,
    ): float {
        if ($classification === MenuEngineeringMatrixService::STAR) {
            return $currentPrice;
        }

        if ($classification === MenuEngineeringMatrixService::PUZZLE) {
            return $currentPrice;
        }

        if ($classification === MenuEngineeringMatrixService::PLOWHORSE) {
            $increased = round($currentPrice * (1 + (self::PLOWHORSE_INCREASE_PERCENT / 100)), 0);

            return max($increased, $this->priceForTargetMargin($currentCost, self::TARGET_MARGIN_PERCENT));
        }

        if ($currentMarginPercent < 10.0) {
            return $this->priceForTargetMargin($currentCost, 15.0);
        }

        return $currentPrice;
    }

    private function priceForTargetMargin(float $cost, float $targetMarginPercent): float
    {
        if ($targetMarginPercent >= 100) {
            return round($cost * 2, 0);
        }

        return round($cost / (1 - ($targetMarginPercent / 100)), 0);
    }

    private function estimateMonthlyQuantity(
        int $menuItemId,
        int $outletId,
        ?string $fromDate,
        ?string $toDate,
    ): float {
        $qty = $this->popularityService->calculatePopularity($menuItemId, $outletId, $fromDate, $toDate);

        return max($qty, 1.0) * 4;
    }
}
