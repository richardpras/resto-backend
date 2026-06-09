<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class MenuPriceSimulationService
{
    public function __construct(
        private readonly RecipeCostService $recipeCostService,
        private readonly MenuProfitabilityService $profitabilityService,
        private readonly MenuProfitabilityAuditService $auditService,
    ) {}

    /** @param array<int,float|int|string> $proposedPrices */
    public function simulate(
        int $menuItemId,
        int $outletId,
        array $proposedPrices,
        ?User $actor = null,
    ): array {
        $breakdown = $this->recipeCostService->calculateMenuCostBreakdown(
            $menuItemId,
            $outletId,
            logCalculated: false,
        );

        $currentPrice = (float) $breakdown['sellingPrice'];
        $currentCost = (float) $breakdown['finalTheoreticalCost'];
        $currentSnapshot = $this->profitabilityService->buildMarginSnapshot($currentPrice, $currentCost);

        $simulations = [];
        foreach ($proposedPrices as $proposedPrice) {
            $price = (float) $proposedPrice;
            $proposedSnapshot = $this->profitabilityService->buildMarginSnapshot($price, $currentCost);

            $simulations[] = [
                'currentPrice' => $currentPrice,
                'proposedPrice' => $price,
                'currentCost' => $currentCost,
                'currentMargin' => $currentSnapshot['margin'],
                'currentMarginPercent' => $currentSnapshot['marginPercent'],
                'proposedMargin' => $proposedSnapshot['margin'],
                'proposedMarginPercent' => $proposedSnapshot['marginPercent'],
                'profitabilityChange' => round($proposedSnapshot['margin'] - $currentSnapshot['margin'], 4),
                'classification' => $proposedSnapshot['classification'],
            ];
        }

        $this->auditService->log('menu_profitability_simulated', $menuItemId, $outletId, $actor, [
            'simulationCount' => count($simulations),
            'currentPrice' => $currentPrice,
            'currentCost' => $currentCost,
        ]);

        $primary = $simulations[0] ?? null;

        return [
            'menuItemId' => (string) $menuItemId,
            'outletId' => $outletId,
            'currentPrice' => $currentPrice,
            'proposedPrice' => $primary['proposedPrice'] ?? null,
            'currentCost' => $currentCost,
            'currentMargin' => $currentSnapshot['margin'],
            'currentMarginPercent' => $currentSnapshot['marginPercent'],
            'proposedMargin' => $primary['proposedMargin'] ?? null,
            'proposedMarginPercent' => $primary['proposedMarginPercent'] ?? null,
            'profitabilityChange' => $primary['profitabilityChange'] ?? null,
            'classification' => $primary['classification'] ?? null,
            'simulations' => $simulations,
        ];
    }
}
