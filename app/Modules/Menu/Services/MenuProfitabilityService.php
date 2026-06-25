<?php

namespace App\Modules\Menu\Services;

use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

final class MenuProfitabilityService
{
    public function __construct(
        private readonly RecipeCostService $recipeCostService,
        private readonly MenuProfitabilityClassificationService $classificationService,
        private readonly MenuProfitabilityAuditService $auditService,
    ) {}

    public function calculateMargin(float $sellingPrice, float $cost): float
    {
        return round($sellingPrice - $cost, 4);
    }

    public function calculateMarginPercent(float $sellingPrice, float $cost): float
    {
        if ($sellingPrice <= 0) {
            return 0.0;
        }

        return round((($sellingPrice - $cost) / $sellingPrice) * 100, 4);
    }

    public function calculateContributionMargin(float $sellingPrice, float $variableCost): float
    {
        return $this->calculateMargin($sellingPrice, $variableCost);
    }

    /** @return array<string,mixed> */
    public function calculateProfitability(int $menuItemId, int $outletId, ?User $actor = null): array
    {
        $breakdown = $this->recipeCostService->calculateMenuCostBreakdown(
            $menuItemId,
            $outletId,
            logCalculated: false,
        );

        $sellingPrice = (float) $breakdown['sellingPrice'];
        $cost = (float) $breakdown['finalTheoreticalCost'];
        $margin = $this->calculateMargin($sellingPrice, $cost);
        $marginPercent = $this->calculateMarginPercent($sellingPrice, $cost);
        $contributionMargin = $this->calculateContributionMargin($sellingPrice, $cost);
        $classification = $this->classificationService->classify($marginPercent);

        $result = [
            'menuItemId' => (string) $menuItemId,
            'menuItemName' => $breakdown['menuItemName'],
            'outletId' => $outletId,
            'sellingPrice' => $sellingPrice,
            'cost' => $cost,
            'profitability' => [
                'margin' => $margin,
                'marginPercent' => $marginPercent,
                'contributionMargin' => $contributionMargin,
            ],
            'margin' => $margin,
            'marginPercent' => $marginPercent,
            'contributionMargin' => $contributionMargin,
            'classification' => $classification,
        ];

        $this->auditService->log('menu_profitability_calculated', $menuItemId, $outletId, $actor, [
            'margin' => $margin,
            'marginPercent' => $marginPercent,
            'classification' => $classification,
        ]);
        $this->auditService->log('profitability_classification_generated', $menuItemId, $outletId, $actor, [
            'classification' => $classification,
            'marginPercent' => $marginPercent,
        ]);

        return $result;
    }

    /**
     * @param list<int> $menuItemIds
     * @return list<array<string,mixed>>
     */
    public function calculateProfitabilityBatch(array $menuItemIds, int $outletId, ?User $actor = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $menuItemIds),
            static fn (int $id): bool => $id >= 1,
        )));

        $results = [];
        foreach ($ids as $menuItemId) {
            try {
                $results[] = $this->calculateProfitability($menuItemId, $outletId, $actor);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if ($e->getStatusCode() !== Response::HTTP_NOT_FOUND) {
                    throw $e;
                }
            }
        }

        return $results;
    }

    /** @return array<string,mixed> */
    public function buildMarginSnapshot(float $sellingPrice, float $cost): array
    {
        $margin = $this->calculateMargin($sellingPrice, $cost);
        $marginPercent = $this->calculateMarginPercent($sellingPrice, $cost);

        return [
            'margin' => $margin,
            'marginPercent' => $marginPercent,
            'contributionMargin' => $this->calculateContributionMargin($sellingPrice, $cost),
            'classification' => $this->classificationService->classify($marginPercent),
        ];
    }
}
