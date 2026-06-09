<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuOptimizationSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MenuOptimizationSnapshotService
{
    public function __construct(
        private readonly MenuOptimizationService $optimizationService,
        private readonly MenuOptimizationAuditService $auditService,
    ) {}

    /** @return Collection<int, MenuOptimizationSnapshot> */
    public function createSnapshot(
        int $outletId,
        ?string $snapshotDate = null,
        ?User $actor = null,
    ): Collection {
        $date = $snapshotDate ?? now()->toDateString();
        $recommendations = $this->optimizationService->generateRecommendations($outletId, $date, $date, $actor);
        $snapshots = collect();

        DB::transaction(function () use ($outletId, $date, $recommendations, $actor, $snapshots): void {
            foreach ($recommendations['recommendations'] as $rec) {
                $menuItemId = (int) $rec['menuItemId'];
                $this->persistRow(
                    $outletId,
                    $date,
                    $menuItemId,
                    MenuOptimizationSnapshot::TYPE_ENGINEERING,
                    $rec,
                    (float) ($rec['marginPercent'] ?? 0),
                    0.0,
                    $snapshots,
                );
            }

            foreach ($recommendations['pricing']['opportunities'] ?? [] as $priceRec) {
                $menuItemId = (int) $priceRec['menuItemId'];
                $this->persistRow(
                    $outletId,
                    $date,
                    $menuItemId,
                    MenuOptimizationSnapshot::TYPE_PRICE,
                    $priceRec,
                    (float) ($priceRec['projectedMarginPercent'] ?? 0),
                    (float) ($priceRec['projectedMonthlyProfitIncrease'] ?? 0),
                    $snapshots,
                );
            }

            foreach ($recommendations['ingredients']['opportunities'] ?? [] as $ingRec) {
                $menuItemId = (int) $ingRec['menuItemId'];
                $this->persistRow(
                    $outletId,
                    $date,
                    $menuItemId,
                    MenuOptimizationSnapshot::TYPE_INGREDIENT,
                    $ingRec,
                    (float) ($ingRec['marginIncreasePercent'] ?? 0),
                    (float) ($ingRec['savingsAmount'] ?? 0),
                    $snapshots,
                );
            }

            foreach ($recommendations['yield']['opportunities'] ?? [] as $yieldRec) {
                $menuItemId = (int) $yieldRec['menuItemId'];
                $this->persistRow(
                    $outletId,
                    $date,
                    $menuItemId,
                    MenuOptimizationSnapshot::TYPE_YIELD,
                    $yieldRec,
                    (float) ($yieldRec['projectedMarginPercent'] ?? 0),
                    (float) ($yieldRec['projectedSavings'] ?? 0),
                    $snapshots,
                );
            }

            $this->auditService->log('optimization_snapshot_created', $outletId, $outletId, $actor, [
                'snapshotDate' => $date,
                'rowCount' => $snapshots->count(),
            ], entityType: 'outlet');
        });

        return $snapshots;
    }

    /** @return Collection<int, MenuOptimizationSnapshot> */
    public function getSnapshots(int $outletId, ?string $snapshotDate = null): Collection
    {
        $query = MenuOptimizationSnapshot::query()
            ->where('outlet_id', $outletId)
            ->orderBy('menu_item_id')
            ->orderBy('recommendation_type');

        if ($snapshotDate !== null) {
            $query->whereDate('snapshot_date', $snapshotDate);
        }

        return $query->get();
    }

    /** @param array<string,mixed> $payload */
    private function persistRow(
        int $outletId,
        string $date,
        int $menuItemId,
        string $type,
        array $payload,
        float $projectedMarginPercent,
        float $projectedProfitIncrease,
        Collection $snapshots,
    ): void {
        $existing = MenuOptimizationSnapshot::query()
            ->where('snapshot_date', $date)
            ->where('outlet_id', $outletId)
            ->where('menu_item_id', $menuItemId)
            ->where('recommendation_type', $type)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $snapshots->push($existing);

            return;
        }

        $snapshot = MenuOptimizationSnapshot::query()->create([
            'snapshot_date' => $date,
            'outlet_id' => $outletId,
            'menu_item_id' => $menuItemId,
            'recommendation_type' => $type,
            'recommendation_json' => $payload,
            'projected_margin_percent' => $projectedMarginPercent,
            'projected_profit_increase' => $projectedProfitIncrease,
        ]);

        $snapshots->push($snapshot);
    }
}
