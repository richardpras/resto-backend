<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\InventoryValuation;
use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class InventoryValuationService
{
    public function __construct(
        private readonly InventoryCostingStrategyResolver $strategyResolver,
        private readonly InventoryCostingPolicyService $costingPolicyService,
    ) {}

    public function getCostingMethod(): string
    {
        return $this->costingPolicyService->getMethod();
    }

    public function getAverageCost(int $ingredientId, int $outletId): float
    {
        return $this->strategyResolver->resolve()->getUnitCost($ingredientId, $outletId);
    }

    /** @return array{stockQuantity: float, inventoryValue: float, averageCost: float, lastPurchaseCost: float, lastUpdatedAt: ?string, costingMethod: string} */
    public function getInventoryValue(int $ingredientId, int $outletId): array
    {
        return array_merge(
            $this->strategyResolver->resolve()->getInventoryValue($ingredientId, $outletId),
            ['costingMethod' => $this->getCostingMethod()],
        );
    }

    public function recordPurchase(
        int $ingredientId,
        int $outletId,
        float $qty,
        float $unitCost,
        ?int $grnId = null,
        ?User $actor = null,
        ?int $sourceMovementId = null,
    ): InventoryValuation {
        return $this->strategyResolver->resolve()->recordPurchase(
            $ingredientId,
            $outletId,
            $qty,
            $unitCost,
            $grnId,
            $actor,
            $sourceMovementId,
        );
    }

    public function recordConsumption(
        int $ingredientId,
        int $outletId,
        float $qty,
        ?User $actor = null,
    ): float {
        return $this->strategyResolver->resolve()->recordConsumption($ingredientId, $outletId, $qty, $actor);
    }

    public function recalculate(?int $ingredientId = null, ?int $outletId = null, ?User $actor = null): int
    {
        $pairs = $this->resolveRecalculatePairs($ingredientId, $outletId);
        $strategy = $this->strategyResolver->resolve();
        $rebuilt = 0;

        foreach ($pairs as $pair) {
            $ingredient = (int) $pair['ingredient_id'];
            $outlet = (int) $pair['outlet_id'];
            $movements = StockMovement::query()
                ->where('inventory_item_id', $ingredient)
                ->where('outlet_id', $outlet)
                ->orderBy('id')
                ->get();

            $strategy->rebuildPair($ingredient, $outlet, $actor, $movements);
            $rebuilt++;
        }

        return $rebuilt;
    }

    /** @return Collection<int, InventoryValuation> */
    public function list(?int $outletId = null, ?int $ingredientId = null): Collection
    {
        return InventoryValuation::query()
            ->with('ingredient:id,name,unit')
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
            ->when($ingredientId !== null && $ingredientId > 0, fn ($q) => $q->where('ingredient_id', $ingredientId))
            ->orderBy('outlet_id')
            ->orderBy('ingredient_id')
            ->get();
    }

    public function outletValuationTotal(?int $outletId = null): float
    {
        $query = InventoryValuation::query();
        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        return round((float) $query->sum('inventory_value'), 2);
    }

    /** @return list<array{ingredient_id:int,outlet_id:int}> */
    private function resolveRecalculatePairs(?int $ingredientId, ?int $outletId): array
    {
        $query = DB::table('stock_movements')
            ->select(['inventory_item_id as ingredient_id', 'outlet_id'])
            ->distinct();

        if ($ingredientId !== null && $ingredientId > 0) {
            $query->where('inventory_item_id', $ingredientId);
        }
        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        return $query->get()->map(static fn ($row): array => [
            'ingredient_id' => (int) $row->ingredient_id,
            'outlet_id' => (int) $row->outlet_id,
        ])->all();
    }
}
