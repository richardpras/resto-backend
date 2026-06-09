<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\User;

final class MenuEngineeringMatrixService
{
    public const STAR = 'STAR';
    public const PUZZLE = 'PUZZLE';
    public const PLOWHORSE = 'PLOWHORSE';
    public const DOG = 'DOG';

    public function __construct(
        private readonly MenuPopularityService $popularityService,
        private readonly MenuProfitabilityService $profitabilityService,
        private readonly RecipeCostService $recipeCostService,
        private readonly MenuEngineeringAuditService $auditService,
        private readonly MenuIntelligenceCacheService $cacheService,
    ) {}

    public function classify(
        float $popularityPercent,
        float $contributionMargin,
        float $averagePopularity,
        float $averageMargin,
    ): string {
        $highPopularity = $popularityPercent >= $averagePopularity;
        $highMargin = $contributionMargin >= $averageMargin;

        if ($highPopularity && $highMargin) {
            return self::STAR;
        }
        if (! $highPopularity && $highMargin) {
            return self::PUZZLE;
        }
        if ($highPopularity && ! $highMargin) {
            return self::PLOWHORSE;
        }

        return self::DOG;
    }

    /** @return array<string,mixed> */
    public function generateMatrix(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $suffix = md5(($fromDate ?? '').'|'.($toDate ?? ''));

        return $this->cacheService->remember(
            $outletId,
            MenuIntelligenceCacheService::PREFIX_ENGINEERING,
            MenuIntelligenceCacheService::TTL_ENGINEERING,
            fn (): array => $this->calculateMenuEngineering($outletId, $fromDate, $toDate, $actor),
            $suffix,
        );
    }

    /** @return array<string,mixed> */
    public function calculateMenuEngineering(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $quantities = $this->popularityService->loadSalesQuantities($outletId, $fromDate, $toDate);
        $totalQty = array_sum($quantities);
        $menuIds = $this->menuIdsForOutlet($outletId);

        $items = [];
        foreach ($menuIds as $menuItemId) {
            $qty = (float) ($quantities[$menuItemId] ?? 0);
            $popularityPercent = $totalQty > 0 ? round(($qty / $totalQty) * 100, 4) : 0.0;

            $breakdown = $this->recipeCostService->calculateMenuCostBreakdown($menuItemId, $outletId, logCalculated: false);
            $marginSnapshot = $this->profitabilityService->buildMarginSnapshot(
                (float) $breakdown['sellingPrice'],
                (float) $breakdown['finalTheoreticalCost'],
            );

            $items[] = [
                'menuItemId' => (string) $menuItemId,
                'menuItemName' => $breakdown['menuItemName'],
                'quantitySold' => $qty,
                'popularityPercent' => $popularityPercent,
                'contributionMargin' => (float) $marginSnapshot['contributionMargin'],
                'marginPercent' => (float) $marginSnapshot['marginPercent'],
            ];
        }

        $avgPopularity = $items !== []
            ? round(array_sum(array_column($items, 'popularityPercent')) / count($items), 4)
            : 0.0;
        $avgMargin = $items !== []
            ? round(array_sum(array_column($items, 'contributionMargin')) / count($items), 4)
            : 0.0;

        foreach ($items as &$item) {
            $classification = $this->classify(
                (float) $item['popularityPercent'],
                (float) $item['contributionMargin'],
                $avgPopularity,
                $avgMargin,
            );
            $item['classification'] = $classification;
            $item['popularityScore'] = (float) $item['popularityPercent'];
            $item['marginScore'] = (float) $item['contributionMargin'];
            $this->logClassificationEvent($classification, (int) $item['menuItemId'], $outletId, $actor);
        }
        unset($item);

        $result = [
            'outletId' => $outletId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'benchmarks' => [
                'averagePopularityPercent' => $avgPopularity,
                'averageContributionMargin' => $avgMargin,
            ],
            'items' => $items,
            'summary' => $this->buildSummary($items),
            'analytics' => $this->buildAnalytics($items),
        ];

        $this->auditService->log('menu_engineering_generated', $outletId, $outletId, $actor, [
            'itemCount' => count($items),
        ], entityType: 'outlet');

        return $result;
    }

    /** @param array<int,array<string,mixed>> $items */
    public function filterByClassification(array $items, string $classification): array
    {
        return array_values(array_filter(
            $items,
            static fn (array $row): bool => ($row['classification'] ?? '') === $classification,
        ));
    }

    /** @param array<int,array<string,mixed>> $items */
    private function buildSummary(array $items): array
    {
        $counts = [
            self::STAR => 0,
            self::PUZZLE => 0,
            self::PLOWHORSE => 0,
            self::DOG => 0,
        ];
        foreach ($items as $item) {
            $counts[$item['classification']] = ($counts[$item['classification']] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function buildAnalytics(array $items): array
    {
        $byPopularity = $items;
        $byMargin = $items;
        usort($byPopularity, static fn ($a, $b) => $b['popularityPercent'] <=> $a['popularityPercent']);
        usort($byMargin, static fn ($a, $b) => $b['contributionMargin'] <=> $a['contributionMargin']);

        return [
            'topStars' => $this->filterByClassification($items, self::STAR),
            'topPuzzles' => $this->filterByClassification($items, self::PUZZLE),
            'topPlowhorses' => $this->filterByClassification($items, self::PLOWHORSE),
            'topDogs' => $this->filterByClassification($items, self::DOG),
            'highestMargin' => array_slice($byMargin, 0, 5),
            'lowestMargin' => array_slice(array_reverse($byMargin), 0, 5),
            'highestPopularity' => array_slice($byPopularity, 0, 5),
            'lowestPopularity' => array_slice(array_reverse($byPopularity), 0, 5),
        ];
    }

    private function logClassificationEvent(string $classification, int $menuItemId, int $outletId, ?User $actor): void
    {
        $event = match ($classification) {
            self::STAR => 'menu_star_detected',
            self::PUZZLE => 'menu_puzzle_detected',
            self::PLOWHORSE => 'menu_plowhorse_detected',
            default => 'menu_dog_detected',
        };
        $this->auditService->log($event, $menuItemId, $outletId, $actor, [
            'classification' => $classification,
        ]);
    }

    /** @return array<int,int> */
    private function menuIdsForOutlet(int $outletId): array
    {
        return MenuItem::query()
            ->where(function ($query) use ($outletId): void {
                $query->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
