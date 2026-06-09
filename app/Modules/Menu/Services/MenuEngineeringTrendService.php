<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuEngineeringSnapshot;
use App\Models\User;

final class MenuEngineeringTrendService
{
    public function __construct(
        private readonly MenuEngineeringSnapshotService $snapshotService,
        private readonly MenuEngineeringAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function calculateTrend(
        int $outletId,
        string $fromDate,
        string $toDate,
        ?User $actor = null,
    ): array {
        $comparison = $this->comparePeriods($outletId, $fromDate, $toDate);
        $movements = $this->detectMovement($outletId, $fromDate, $toDate);

        $this->auditService->log('menu_engineering_trend_generated', $outletId, $outletId, $actor, [
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'movementCount' => count($movements),
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'comparison' => $comparison,
            'movements' => $movements,
            'mostImproved' => $this->rankByMarginChange($comparison['changes'], descending: true),
            'mostDeclined' => $this->rankByMarginChange($comparison['changes'], descending: false),
        ];
    }

    /** @return array<string,mixed> */
    public function comparePeriods(int $outletId, string $fromDate, string $toDate): array
    {
        return $this->snapshotService->compareSnapshots($outletId, $fromDate, $toDate);
    }

    /** @return array<int,array<string,mixed>> */
    public function detectMovement(int $outletId, string $fromDate, string $toDate): array
    {
        $comparison = $this->snapshotService->compareSnapshots($outletId, $fromDate, $toDate);
        $movements = [];

        foreach ($comparison['changes'] as $change) {
            if ($change['classificationA'] === $change['classificationB']) {
                continue;
            }

            $movements[] = array_merge($change, [
                'movementType' => $change['classificationA'].' → '.$change['classificationB'],
            ]);

            $this->auditService->log('menu_classification_changed', (int) $change['menuItemId'], $outletId, null, [
                'from' => $change['classificationA'],
                'to' => $change['classificationB'],
            ]);
        }

        return $movements;
    }

    /** @return array<int,array<string,mixed>> */
    public function getMenuItemHistory(int $menuItemId, int $outletId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $query = MenuEngineeringSnapshot::query()
            ->where('menu_item_id', $menuItemId)
            ->where('outlet_id', $outletId)
            ->orderBy('snapshot_date');

        if ($fromDate) {
            $query->whereDate('snapshot_date', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('snapshot_date', '<=', $toDate);
        }

        return $query->get()->map(static fn (MenuEngineeringSnapshot $row): array => [
            'snapshotDate' => $row->snapshot_date?->toDateString(),
            'quantitySold' => (float) $row->quantity_sold,
            'popularityPercent' => (float) $row->popularity_percent,
            'contributionMargin' => (float) $row->contribution_margin,
            'marginPercent' => (float) $row->margin_percent,
            'classification' => $row->classification,
        ])->values()->all();
    }

    /** @param array<int,array<string,mixed>> $changes */
    private function rankByMarginChange(array $changes, bool $descending): array
    {
        $filtered = array_values(array_filter($changes, static fn (array $c): bool => $c['marginChange'] !== 0.0));
        usort($filtered, static fn ($a, $b) => $descending
            ? $b['marginChange'] <=> $a['marginChange']
            : $a['marginChange'] <=> $b['marginChange']);

        return array_slice($filtered, 0, 5);
    }
}
