<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuEngineeringSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MenuEngineeringSnapshotService
{
    public function __construct(
        private readonly MenuEngineeringMatrixService $matrixService,
        private readonly MenuEngineeringAuditService $auditService,
    ) {}

    /** @return Collection<int, MenuEngineeringSnapshot> */
    public function createSnapshot(
        int $outletId,
        ?string $snapshotDate = null,
        ?User $actor = null,
    ): Collection {
        $date = $snapshotDate ?? now()->toDateString();
        $matrix = $this->matrixService->generateMatrix($outletId, $date, $date, $actor);
        $snapshots = collect();

        DB::transaction(function () use ($outletId, $date, $matrix, $actor, $snapshots): void {
            foreach ($matrix['items'] as $item) {
                $menuItemId = (int) $item['menuItemId'];

                $existing = MenuEngineeringSnapshot::query()
                    ->where('snapshot_date', $date)
                    ->where('outlet_id', $outletId)
                    ->where('menu_item_id', $menuItemId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $snapshots->push($existing);

                    continue;
                }

                $snapshot = MenuEngineeringSnapshot::query()->create([
                    'snapshot_date' => $date,
                    'outlet_id' => $outletId,
                    'menu_item_id' => $menuItemId,
                    'quantity_sold' => (float) $item['quantitySold'],
                    'popularity_percent' => (float) $item['popularityPercent'],
                    'contribution_margin' => (float) $item['contributionMargin'],
                    'margin_percent' => (float) $item['marginPercent'],
                    'classification' => (string) $item['classification'],
                ]);

                $snapshots->push($snapshot);
            }

            $this->auditService->log('menu_engineering_snapshot_created', $outletId, $outletId, $actor, [
                'snapshotDate' => $date,
                'itemCount' => $snapshots->count(),
            ], entityType: 'outlet');
        });

        return $snapshots;
    }

    /** @return Collection<int, MenuEngineeringSnapshot> */
    public function getSnapshot(int $outletId, string $snapshotDate): Collection
    {
        return MenuEngineeringSnapshot::query()
            ->where('outlet_id', $outletId)
            ->whereDate('snapshot_date', $snapshotDate)
            ->orderBy('menu_item_id')
            ->get();
    }

    /** @return array<string,mixed> */
    public function compareSnapshots(int $outletId, string $dateA, string $dateB): array
    {
        $snapshotsA = $this->getSnapshot($outletId, $dateA)->keyBy('menu_item_id');
        $snapshotsB = $this->getSnapshot($outletId, $dateB)->keyBy('menu_item_id');
        $menuIds = $snapshotsA->keys()->merge($snapshotsB->keys())->unique();

        $changes = [];
        foreach ($menuIds as $menuItemId) {
            $a = $snapshotsA->get($menuItemId);
            $b = $snapshotsB->get($menuItemId);
            if ($a === null || $b === null) {
                continue;
            }

            $changes[] = [
                'menuItemId' => (string) $menuItemId,
                'classificationA' => $a->classification,
                'classificationB' => $b->classification,
                'popularityChange' => round((float) $b->popularity_percent - (float) $a->popularity_percent, 4),
                'marginChange' => round((float) $b->contribution_margin - (float) $a->contribution_margin, 4),
                'movement' => $a->classification.' → '.$b->classification,
            ];
        }

        return [
            'outletId' => $outletId,
            'dateA' => $dateA,
            'dateB' => $dateB,
            'changes' => $changes,
        ];
    }
}
