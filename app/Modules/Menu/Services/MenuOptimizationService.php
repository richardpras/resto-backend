<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class MenuOptimizationService
{
    public function __construct(
        private readonly MenuEngineeringMatrixService $matrixService,
        private readonly PriceOptimizationService $priceOptimizationService,
        private readonly BundleRecommendationService $bundleService,
        private readonly IngredientOptimizationService $ingredientService,
        private readonly YieldOptimizationService $yieldService,
        private readonly MenuOptimizationAuditService $auditService,
        private readonly MenuIntelligenceCacheService $cacheService,
    ) {}

    /** @return array<string,mixed> */
    public function generateRecommendations(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $suffix = md5(($fromDate ?? '').'|'.($toDate ?? ''));

        return $this->cacheService->remember(
            $outletId,
            MenuIntelligenceCacheService::PREFIX_OPTIMIZATION,
            MenuIntelligenceCacheService::TTL_OPTIMIZATION,
            fn (): array => $this->buildRecommendations($outletId, $fromDate, $toDate, $actor),
            $suffix,
        );
    }

    /** @return array<string,mixed> */
    private function buildRecommendations(
        int $outletId,
        ?string $fromDate,
        ?string $toDate,
        ?User $actor,
    ): array {
        $matrix = $this->matrixService->generateMatrix($outletId, $fromDate, $toDate, $actor);
        $recommendations = [];

        foreach ($matrix['items'] as $item) {
            $recommendations[] = $this->buildEngineeringRecommendation($item);
        }

        $this->auditService->log('optimization_generated', $outletId, $outletId, $actor, [
            'recommendationCount' => count($recommendations),
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'benchmarks' => $matrix['benchmarks'],
            'recommendations' => $recommendations,
            'pricing' => $this->priceOptimizationService->analyzeOutlet($outletId, $fromDate, $toDate),
            'bundles' => $this->bundleService->analyzeOutlet($outletId, $fromDate, $toDate),
            'ingredients' => $this->ingredientService->analyzeOutlet($outletId),
            'yield' => $this->yieldService->analyzeOutlet($outletId),
        ];
    }

    /** @return array<string,mixed> */
    public function recommendationsByClassification(
        int $outletId,
        string $classification,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $all = $this->generateRecommendations($outletId, $fromDate, $toDate, $actor);

        return [
            'outletId' => $outletId,
            'classification' => $classification,
            'recommendations' => array_values(array_filter(
                $all['recommendations'],
                static fn (array $row): bool => ($row['classification'] ?? '') === $classification,
            )),
        ];
    }

    /** @param array<string,mixed> $item */
    private function buildEngineeringRecommendation(array $item): array
    {
        $classification = (string) $item['classification'];
        $actions = match ($classification) {
            MenuEngineeringMatrixService::STAR => [
                'primary' => 'maintain',
                'actions' => ['maintain', 'protect quality', 'avoid discounting'],
            ],
            MenuEngineeringMatrixService::PUZZLE => [
                'primary' => 'marketing_boost',
                'actions' => ['marketing boost', 'reposition menu', 'featured placement'],
            ],
            MenuEngineeringMatrixService::PLOWHORSE => [
                'primary' => 'increase_price',
                'actions' => ['increase price', 'optimize recipe', 'improve yield'],
            ],
            default => [
                'primary' => 'consider_removal',
                'actions' => ['consider removal', 'replace recipe', 'limited promotion'],
            ],
        };

        if ($classification === MenuEngineeringMatrixService::DOG) {
            $this->auditService->log('menu_removal_recommended', (int) $item['menuItemId'], null, null, [
                'classification' => $classification,
            ]);
        }

        return [
            'menuItemId' => $item['menuItemId'],
            'menuItemName' => $item['menuItemName'] ?? null,
            'classification' => $classification,
            'popularityPercent' => (float) $item['popularityPercent'],
            'contributionMargin' => (float) $item['contributionMargin'],
            'marginPercent' => (float) $item['marginPercent'],
            'primaryRecommendation' => $actions['primary'],
            'recommendedActions' => $actions['actions'],
        ];
    }
}
