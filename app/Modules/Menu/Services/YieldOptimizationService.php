<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class YieldOptimizationService
{
    private const WASTE_THRESHOLD = 5.0;

    private const YIELD_THRESHOLD = 95.0;

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

        usort($opportunities, static fn ($a, $b) => $b['projectedSavings'] <=> $a['projectedSavings']);

        $this->auditService->log('yield_optimization_generated', $outletId, $outletId, $actor, [
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
        $yieldPercent = (float) $breakdown['yieldPercent'];
        $wastePercent = (float) $breakdown['wastePercent'];
        $rawCost = (float) $breakdown['rawCost'];
        $currentCost = (float) $breakdown['finalTheoreticalCost'];

        $hasExcessiveWaste = $wastePercent > self::WASTE_THRESHOLD;
        $hasAbnormalYield = $yieldPercent < self::YIELD_THRESHOLD;

        if (! $hasExcessiveWaste && ! $hasAbnormalYield) {
            return null;
        }

        $improvedYield = $hasAbnormalYield ? min(100.0, $yieldPercent + 5.0) : $yieldPercent;
        $improvedWaste = $hasExcessiveWaste ? max(0.0, $wastePercent / 2) : $wastePercent;

        $yieldAdjusted = $this->recipeCostService->calculateYieldAdjustedCost($rawCost, $improvedYield);
        $optimizedCost = $this->recipeCostService->calculateWasteAdjustedCost($yieldAdjusted, $improvedWaste);
        $projectedSavings = round($currentCost - $optimizedCost, 4);

        if ($projectedSavings <= 0) {
            return null;
        }

        $sellingPrice = (float) $breakdown['sellingPrice'];
        $currentMargin = $this->profitabilityService->buildMarginSnapshot($sellingPrice, $currentCost);
        $optimizedMargin = $this->profitabilityService->buildMarginSnapshot($sellingPrice, $optimizedCost);

        $issues = [];
        if ($hasExcessiveWaste) {
            $issues[] = 'excessive_waste';
        }
        if ($hasAbnormalYield) {
            $issues[] = 'abnormal_yield_loss';
        }

        return [
            'menuItemId' => (string) $menuItemId,
            'menuItemName' => $breakdown['menuItemName'],
            'yieldPercent' => $yieldPercent,
            'wastePercent' => $wastePercent,
            'issues' => $issues,
            'currentCost' => $currentCost,
            'optimizedCost' => $optimizedCost,
            'projectedSavings' => $projectedSavings,
            'currentMarginPercent' => (float) $currentMargin['marginPercent'],
            'projectedMarginPercent' => (float) $optimizedMargin['marginPercent'],
            'recommendations' => $this->buildRecommendations($hasExcessiveWaste, $hasAbnormalYield),
        ];
    }

    /** @return array<int,string> */
    private function buildRecommendations(bool $excessiveWaste, bool $abnormalYield): array
    {
        $recs = [];
        if ($excessiveWaste) {
            $recs[] = 'Reduce prep waste through portion control and staff training';
        }
        if ($abnormalYield) {
            $recs[] = 'Improve yield through better trimming and cooking techniques';
        }

        return $recs;
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
