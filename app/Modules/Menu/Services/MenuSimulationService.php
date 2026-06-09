<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class MenuSimulationService
{
    public function __construct(
        private readonly RecipeCostService $recipeCostService,
        private readonly MenuProfitabilityService $profitabilityService,
        private readonly MenuPopularityService $popularityService,
        private readonly MenuOptimizationAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function simulatePrice(
        int $menuItemId,
        int $outletId,
        float $newPrice,
        ?User $actor = null,
    ): array {
        $breakdown = $this->recipeCostService->calculateMenuCostBreakdown($menuItemId, $outletId, logCalculated: false);
        $currentPrice = (float) $breakdown['sellingPrice'];
        $currentCost = (float) $breakdown['finalTheoreticalCost'];
        $currentSnapshot = $this->profitabilityService->buildMarginSnapshot($currentPrice, $currentCost);
        $proposedSnapshot = $this->profitabilityService->buildMarginSnapshot($newPrice, $currentCost);
        $monthlyQty = max($this->popularityService->calculatePopularity($menuItemId, $outletId), 1.0) * 4;
        $marginDelta = (float) $proposedSnapshot['margin'] - (float) $currentSnapshot['margin'];

        $result = [
            'menuItemId' => (string) $menuItemId,
            'outletId' => $outletId,
            'simulationType' => 'price',
            'currentPrice' => $currentPrice,
            'newPrice' => $newPrice,
            'currentCost' => $currentCost,
            'currentMargin' => (float) $currentSnapshot['margin'],
            'currentMarginPercent' => (float) $currentSnapshot['marginPercent'],
            'newMargin' => (float) $proposedSnapshot['margin'],
            'newMarginPercent' => (float) $proposedSnapshot['marginPercent'],
            'marginImpact' => round($marginDelta, 4),
            'projectedMonthlyProfit' => round($marginDelta * $monthlyQty, 4),
            'foodCostPercent' => $newPrice > 0 ? round(($currentCost / $newPrice) * 100, 4) : 0.0,
        ];

        $this->auditService->log('simulation_executed', $menuItemId, $outletId, $actor, [
            'type' => 'price',
            'newPrice' => $newPrice,
        ]);

        return $result;
    }

    /**
     * @param array<int,array{inventoryItemId:int|string,quantity?:float,newUnitCost?:float}> $changes
     *
     * @return array<string,mixed>
     */
    public function simulateRecipe(
        int $menuItemId,
        int $outletId,
        array $changes,
        ?User $actor = null,
    ): array {
        $breakdown = $this->recipeCostService->calculateMenuCostBreakdown($menuItemId, $outletId, logCalculated: false);
        $currentCost = (float) $breakdown['finalTheoreticalCost'];
        $sellingPrice = (float) $breakdown['sellingPrice'];
        $currentSnapshot = $this->profitabilityService->buildMarginSnapshot($sellingPrice, $currentCost);

        $rawCost = (float) $breakdown['rawCost'];
        $ingredientMap = [];
        foreach ($breakdown['ingredients'] as $line) {
            $ingredientMap[(int) $line['inventoryItemId']] = $line;
        }

        foreach ($changes as $change) {
            $ingredientId = (int) $change['inventoryItemId'];
            $mapKey = array_key_exists($ingredientId, $ingredientMap)
                ? $ingredientId
                : $this->findIngredientMapKey($ingredientMap, $ingredientId);

            if ($mapKey === null) {
                continue;
            }

            $line = $ingredientMap[$mapKey];
            $qty = isset($change['quantity']) ? (float) $change['quantity'] : (float) $line['quantity'];
            $unitCost = isset($change['newUnitCost']) ? (float) $change['newUnitCost'] : (float) $line['averageCost'];
            $ingredientMap[$mapKey]['lineCost'] = round($unitCost * $qty, 4);
            $ingredientMap[$mapKey]['quantity'] = $qty;
        }

        $newRawCost = round(array_sum(array_column(array_values($ingredientMap), 'lineCost')), 4);
        $yieldAdjusted = $this->recipeCostService->calculateYieldAdjustedCost($newRawCost, (float) $breakdown['yieldPercent']);
        $newCost = $this->recipeCostService->calculateWasteAdjustedCost($yieldAdjusted, (float) $breakdown['wastePercent']);
        $newSnapshot = $this->profitabilityService->buildMarginSnapshot($sellingPrice, $newCost);

        $result = [
            'menuItemId' => (string) $menuItemId,
            'outletId' => $outletId,
            'simulationType' => 'recipe',
            'currentTheoreticalCost' => $currentCost,
            'newTheoreticalCost' => $newCost,
            'costReduction' => round($currentCost - $newCost, 4),
            'currentFoodCostPercent' => $sellingPrice > 0 ? round(($currentCost / $sellingPrice) * 100, 4) : 0.0,
            'newFoodCostPercent' => $sellingPrice > 0 ? round(($newCost / $sellingPrice) * 100, 4) : 0.0,
            'currentMarginPercent' => (float) $currentSnapshot['marginPercent'],
            'newMarginPercent' => (float) $newSnapshot['marginPercent'],
            'profitabilityImpact' => round((float) $newSnapshot['margin'] - (float) $currentSnapshot['margin'], 4),
        ];

        $this->auditService->log('simulation_executed', $menuItemId, $outletId, $actor, [
            'type' => 'recipe',
            'changeCount' => count($changes),
        ]);

        return $result;
    }

    /** @return array<string,mixed> */
    public function simulateYield(
        int $menuItemId,
        int $outletId,
        float $newYieldPercent,
        ?User $actor = null,
    ): array {
        $breakdown = $this->recipeCostService->calculateMenuCostBreakdown($menuItemId, $outletId, logCalculated: false);
        $rawCost = (float) $breakdown['rawCost'];
        $currentCost = (float) $breakdown['finalTheoreticalCost'];
        $sellingPrice = (float) $breakdown['sellingPrice'];
        $currentSnapshot = $this->profitabilityService->buildMarginSnapshot($sellingPrice, $currentCost);

        $yieldAdjusted = $this->recipeCostService->calculateYieldAdjustedCost($rawCost, $newYieldPercent);
        $newCost = $this->recipeCostService->calculateWasteAdjustedCost($yieldAdjusted, (float) $breakdown['wastePercent']);
        $newSnapshot = $this->profitabilityService->buildMarginSnapshot($sellingPrice, $newCost);
        $costReduction = round($currentCost - $newCost, 4);

        $result = [
            'menuItemId' => (string) $menuItemId,
            'outletId' => $outletId,
            'simulationType' => 'yield',
            'currentYieldPercent' => (float) $breakdown['yieldPercent'],
            'newYieldPercent' => $newYieldPercent,
            'currentCost' => $currentCost,
            'newCost' => $newCost,
            'costReduction' => $costReduction,
            'currentMarginPercent' => (float) $currentSnapshot['marginPercent'],
            'newMarginPercent' => (float) $newSnapshot['marginPercent'],
            'profitabilityImpact' => round((float) $newSnapshot['margin'] - (float) $currentSnapshot['margin'], 4),
        ];

        $this->auditService->log('simulation_executed', $menuItemId, $outletId, $actor, [
            'type' => 'yield',
            'newYieldPercent' => $newYieldPercent,
        ]);

        return $result;
    }

    /** @param array<int,array<string,mixed>> $ingredientMap */
    private function findIngredientMapKey(array $ingredientMap, int $ingredientId): ?int
    {
        foreach ($ingredientMap as $key => $line) {
            if ((int) ($line['inventoryItemId'] ?? 0) === $ingredientId) {
                return (int) $key;
            }
        }

        return null;
    }
}
