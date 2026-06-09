<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Orders\Domain\OrderItemCostSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;

final class HistoricalMarginService
{
    public function __construct(
        private readonly RecipeCostService $recipeCostService,
        private readonly MenuProfitabilityService $profitabilityService,
        private readonly MenuProfitabilityAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function compareHistoricalMargins(
        int $menuItemId,
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $breakdown = $this->recipeCostService->calculateMenuCostBreakdown(
            $menuItemId,
            $outletId,
            logCalculated: false,
        );

        $sellingPrice = (float) $breakdown['sellingPrice'];
        $currentCost = (float) $breakdown['finalTheoreticalCost'];
        $currentSnapshot = $this->profitabilityService->buildMarginSnapshot($sellingPrice, $currentCost);

        $query = OrderItemCostSnapshot::query()
            ->where('menu_item_id', $menuItemId)
            ->where('outlet_id', $outletId);

        if ($fromDate !== null && $fromDate !== '') {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate !== null && $toDate !== '') {
            $query->whereDate('created_at', '<=', $toDate);
        }

        /** @var Collection<int, OrderItemCostSnapshot> $snapshots */
        $snapshots = $query->orderByDesc('created_at')->get();

        $rows = $snapshots->map(function (OrderItemCostSnapshot $snapshot) use ($sellingPrice, $currentCost, $currentSnapshot): array {
            $historicalCost = (float) $snapshot->cost_per_unit;
            $costVariance = round($currentCost - $historicalCost, 4);
            $variancePercent = $historicalCost > 0
                ? round(($costVariance / $historicalCost) * 100, 4)
                : 0.0;

            $historicalSnapshot = $this->profitabilityService->buildMarginSnapshot($sellingPrice, $historicalCost);

            return [
                'orderItemId' => (string) $snapshot->order_item_id,
                'historicalCost' => $historicalCost,
                'currentCost' => $currentCost,
                'costVariance' => $costVariance,
                'variancePercent' => $variancePercent,
                'historicalMargin' => $historicalSnapshot['margin'],
                'historicalMarginPercent' => $historicalSnapshot['marginPercent'],
                'currentMargin' => $currentSnapshot['margin'],
                'currentMarginPercent' => $currentSnapshot['marginPercent'],
                'marginVariance' => round($currentSnapshot['margin'] - $historicalSnapshot['margin'], 4),
                'snapshotAt' => $snapshot->created_at?->toIso8601String(),
            ];
        })->values()->all();

        $latest = $rows[0] ?? null;

        $this->auditService->log('historical_margin_compared', $menuItemId, $outletId, $actor, [
            'currentCost' => $currentCost,
            'snapshotCount' => count($rows),
        ]);

        return [
            'menuItemId' => (string) $menuItemId,
            'outletId' => $outletId,
            'sellingPrice' => $sellingPrice,
            'historicalCost' => $latest['historicalCost'] ?? null,
            'currentCost' => $currentCost,
            'costVariance' => $latest['costVariance'] ?? 0.0,
            'variancePercent' => $latest['variancePercent'] ?? 0.0,
            'historicalMargin' => $latest['historicalMargin'] ?? null,
            'currentMargin' => $currentSnapshot['margin'],
            'historicalMarginPercent' => $latest['historicalMarginPercent'] ?? null,
            'currentMarginPercent' => $currentSnapshot['marginPercent'],
            'comparisons' => $rows,
        ];
    }
}
