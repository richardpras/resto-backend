<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class IngredientOptimizationService
{
    private const COST_REDUCTION_PERCENT = 8.0;

    public function __construct(
        private readonly RecipeCostService $recipeCostService,
        private readonly MenuProfitabilityService $profitabilityService,
        private readonly MenuOptimizationAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function analyzeOutlet(int $outletId, ?User $actor = null): array
    {
        $menuIds = $this->menuIdsForOutlet($outletId);
        $opportunities = [];

        foreach ($menuIds as $menuItemId) {
            $analysis = $this->analyzeMenuItem($menuItemId, $outletId);
            if ($analysis !== null) {
                $opportunities[] = $analysis;
            }
        }

        usort($opportunities, static fn ($a, $b) => $b['savingsAmount'] <=> $a['savingsAmount']);

        $this->auditService->log('ingredient_optimization_generated', $outletId, $outletId, $actor, [
            'opportunityCount' => count($opportunities),
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'opportunities' => $opportunities,
        ];
    }

    /** @return array<string,mixed>|null */
    public function analyzeMenuItem(int $menuItemId, int $outletId): ?array
    {
        $breakdown = $this->recipeCostService->calculateMenuCostBreakdown($menuItemId, $outletId, logCalculated: false);
        $ingredients = $breakdown['ingredients'] ?? [];

        if ($ingredients === []) {
            return null;
        }

        usort($ingredients, static fn ($a, $b) => $b['lineCost'] <=> $a['lineCost']);
        $topLine = $ingredients[0];
        $currentCost = (float) $breakdown['finalTheoreticalCost'];
        $lineSavings = round((float) $topLine['lineCost'] * (self::COST_REDUCTION_PERCENT / 100), 4);
        $optimizedCost = round(max(0, $currentCost - $lineSavings), 4);

        $sellingPrice = (float) $breakdown['sellingPrice'];
        $currentMargin = $this->profitabilityService->buildMarginSnapshot($sellingPrice, $currentCost);
        $optimizedMargin = $this->profitabilityService->buildMarginSnapshot($sellingPrice, $optimizedCost);
        $marginIncrease = round((float) $optimizedMargin['marginPercent'] - (float) $currentMargin['marginPercent'], 4);

        if ($lineSavings <= 0) {
            return null;
        }

        return [
            'menuItemId' => (string) $menuItemId,
            'menuItemName' => $breakdown['menuItemName'],
            'currentCost' => $currentCost,
            'optimizedCost' => $optimizedCost,
            'savingsAmount' => $lineSavings,
            'marginIncreasePercent' => $marginIncrease,
            'topIngredient' => [
                'inventoryItemId' => $topLine['inventoryItemId'],
                'ingredientName' => $topLine['ingredientName'],
                'lineCost' => (float) $topLine['lineCost'],
                'recommendation' => 'Negotiate supplier pricing or evaluate ingredient replacement',
            ],
            'recommendations' => [
                'cost_reduction' => 'Target '.self::COST_REDUCTION_PERCENT.'% reduction on highest-cost ingredient',
                'ingredient_replacement' => 'Evaluate substitute for '.$topLine['ingredientName'],
            ],
        ];
    }

    /** @return array<int,int> */
    private function menuIdsForOutlet(int $outletId): array
    {
        return \App\Models\Modules\Menu\Domain\MenuItem::query()
            ->where(function ($query) use ($outletId): void {
                $query->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
