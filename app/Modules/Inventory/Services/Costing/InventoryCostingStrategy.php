<?php

namespace App\Modules\Inventory\Services\Costing;

use App\Models\Modules\Inventory\Domain\InventoryValuation;
use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\User;
use Illuminate\Support\Collection;

interface InventoryCostingStrategy
{
    public function getUnitCost(int $ingredientId, int $outletId): float;

    /** @return array{stockQuantity: float, inventoryValue: float, averageCost: float, lastPurchaseCost: float, lastUpdatedAt: ?string} */
    public function getInventoryValue(int $ingredientId, int $outletId): array;

    public function recordPurchase(
        int $ingredientId,
        int $outletId,
        float $qty,
        float $unitCost,
        ?int $grnId = null,
        ?User $actor = null,
        ?int $sourceMovementId = null,
    ): InventoryValuation;

    public function recordConsumption(
        int $ingredientId,
        int $outletId,
        float $qty,
        ?User $actor = null,
    ): float;

    public function rebuildPair(int $ingredientId, int $outletId, ?User $actor, Collection $movements): void;
}
