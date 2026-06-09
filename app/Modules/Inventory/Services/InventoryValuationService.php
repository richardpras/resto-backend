<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Inventory\Domain\InventoryValuation;
use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class InventoryValuationService
{
    public function __construct(
        private readonly InventoryValuationAuditService $auditService,
    ) {}

    public function getAverageCost(int $ingredientId, int $outletId): float
    {
        $row = InventoryValuation::query()
            ->where('ingredient_id', $ingredientId)
            ->where('outlet_id', $outletId)
            ->first();

        if ($row !== null && (float) $row->average_cost > 0) {
            return (float) $row->average_cost;
        }

        return 0.0;
    }

    /** @return array{stockQuantity: float, inventoryValue: float, averageCost: float, lastPurchaseCost: float, lastUpdatedAt: ?string} */
    public function getInventoryValue(int $ingredientId, int $outletId): array
    {
        $row = InventoryValuation::query()
            ->where('ingredient_id', $ingredientId)
            ->where('outlet_id', $outletId)
            ->first();

        if ($row === null) {
            return [
                'stockQuantity' => 0.0,
                'inventoryValue' => 0.0,
                'averageCost' => 0.0,
                'lastPurchaseCost' => 0.0,
                'lastUpdatedAt' => null,
            ];
        }

        return [
            'stockQuantity' => (float) $row->stock_quantity,
            'inventoryValue' => round((float) $row->inventory_value, 4),
            'averageCost' => (float) $row->average_cost,
            'lastPurchaseCost' => (float) $row->last_purchase_cost,
            'lastUpdatedAt' => $row->last_updated_at?->toIso8601String(),
        ];
    }

    public function recordPurchase(
        int $ingredientId,
        int $outletId,
        float $qty,
        float $unitCost,
        ?int $grnId = null,
        ?User $actor = null,
    ): InventoryValuation {
        abort_if($qty <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Purchase quantity must be positive.');
        abort_if($unitCost < 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Purchase unit cost cannot be negative.');

        return DB::transaction(function () use ($ingredientId, $outletId, $qty, $unitCost, $grnId, $actor): InventoryValuation {
            $row = $this->lockOrCreate($ingredientId, $outletId);

            $oldQty = (float) $row->stock_quantity;
            $oldAvg = (float) $row->average_cost;
            $newQty = $oldQty + $qty;
            $newAvg = $newQty > 0
                ? (($oldQty * $oldAvg) + ($qty * $unitCost)) / $newQty
                : 0.0;

            $row->fill([
                'stock_quantity' => $newQty,
                'average_cost' => round($newAvg, 4),
                'inventory_value' => round($newQty * $newAvg, 4),
                'last_purchase_cost' => round($unitCost, 4),
                'last_grn_id' => $grnId,
                'last_updated_at' => now(),
            ]);
            $row->save();

            $this->syncStockQuantityFromLedger($ingredientId, $outletId, $row);

            $this->auditService->log(
                'inventory_average_cost_updated',
                (int) $row->id,
                $outletId,
                $actor,
                [
                    'ingredientId' => $ingredientId,
                    'receivedQty' => $qty,
                    'unitCost' => $unitCost,
                    'averageCost' => (float) $row->average_cost,
                    'grnId' => $grnId,
                ],
            );

            return $row->fresh();
        });
    }

    public function recordConsumption(
        int $ingredientId,
        int $outletId,
        float $qty,
        ?User $actor = null,
    ): float {
        abort_if($qty <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Consumption quantity must be positive.');

        return DB::transaction(function () use ($ingredientId, $outletId, $qty, $actor): float {
            $row = $this->lockOrCreate($ingredientId, $outletId);
            $unitCost = (float) $row->average_cost;

            $row->stock_quantity = max(0, (float) $row->stock_quantity - $qty);
            $row->inventory_value = round((float) $row->stock_quantity * $unitCost, 4);
            if ((float) $row->stock_quantity <= 0) {
                $row->inventory_value = 0.0;
            }
            $row->last_updated_at = now();
            $row->save();

            $this->syncStockQuantityFromLedger($ingredientId, $outletId, $row);

            $this->auditService->log(
                'inventory_cost_consumed',
                (int) $row->id,
                $outletId,
                $actor,
                [
                    'ingredientId' => $ingredientId,
                    'qty' => $qty,
                    'unitCost' => $unitCost,
                    'remainingQty' => (float) $row->stock_quantity,
                ],
            );

            return $unitCost;
        });
    }

    public function recalculate(?int $ingredientId = null, ?int $outletId = null, ?User $actor = null): int
    {
        $pairs = $this->resolveRecalculatePairs($ingredientId, $outletId);
        $rebuilt = 0;

        foreach ($pairs as $pair) {
            $this->rebuildPair((int) $pair['ingredient_id'], (int) $pair['outlet_id'], $actor);
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

    private function rebuildPair(int $ingredientId, int $outletId, ?User $actor): void
    {
        DB::transaction(function () use ($ingredientId, $outletId, $actor): void {
            $row = $this->lockOrCreate($ingredientId, $outletId);
            $row->fill([
                'stock_quantity' => 0,
                'inventory_value' => 0,
                'average_cost' => 0,
                'last_purchase_cost' => 0,
                'last_grn_id' => null,
                'last_updated_at' => now(),
            ]);
            $row->save();

            $movements = StockMovement::query()
                ->where('inventory_item_id', $ingredientId)
                ->where('outlet_id', $outletId)
                ->orderBy('id')
                ->get();

            foreach ($movements as $movement) {
                $qty = (float) $movement->quantity;
                if ($qty <= 0) {
                    continue;
                }

                if (in_array((string) $movement->type, ['purchase', 'adjustment'], true)) {
                    $unitCost = (float) ($movement->unit_cost ?? 0);
                    $this->applyPurchaseToRow($row, $qty, $unitCost);
                    if ((string) $movement->type === 'purchase') {
                        $row->last_purchase_cost = round($unitCost, 4);
                    }
                } elseif (in_array((string) $movement->type, ['sale', 'waste'], true)) {
                    $this->applyConsumptionToRow($row, $qty);
                }
            }

            $row->last_updated_at = now();
            $row->save();

            $this->auditService->log(
                'inventory_valuation_rebuilt',
                (int) $row->id,
                $outletId,
                $actor,
                [
                    'ingredientId' => $ingredientId,
                    'stockQuantity' => (float) $row->stock_quantity,
                    'averageCost' => (float) $row->average_cost,
                    'inventoryValue' => (float) $row->inventory_value,
                ],
            );
        });
    }

    private function applyPurchaseToRow(InventoryValuation $row, float $qty, float $unitCost): void
    {
        $oldQty = (float) $row->stock_quantity;
        $oldAvg = (float) $row->average_cost;
        $newQty = $oldQty + $qty;
        $newAvg = $newQty > 0
            ? (($oldQty * $oldAvg) + ($qty * $unitCost)) / $newQty
            : 0.0;

        $row->stock_quantity = $newQty;
        $row->average_cost = round($newAvg, 4);
        $row->inventory_value = round($newQty * $newAvg, 4);
    }

    private function applyConsumptionToRow(InventoryValuation $row, float $qty): void
    {
        $unitCost = (float) $row->average_cost;
        $row->stock_quantity = max(0, (float) $row->stock_quantity - $qty);
        $row->inventory_value = round((float) $row->stock_quantity * $unitCost, 4);
        if ((float) $row->stock_quantity <= 0) {
            $row->inventory_value = 0.0;
        }
    }

    private function lockOrCreate(int $ingredientId, int $outletId): InventoryValuation
    {
        $existing = InventoryValuation::query()
            ->where('ingredient_id', $ingredientId)
            ->where('outlet_id', $outletId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return InventoryValuation::query()->create([
            'ingredient_id' => $ingredientId,
            'outlet_id' => $outletId,
            'stock_quantity' => 0,
            'inventory_value' => 0,
            'average_cost' => 0,
            'last_purchase_cost' => 0,
            'last_updated_at' => now(),
        ]);
    }

    private function syncStockQuantityFromLedger(int $ingredientId, int $outletId, InventoryValuation $row): void
    {
        $bucket = InventoryStock::query()
            ->where('ingredient_id', $ingredientId)
            ->where('outlet_id', $outletId)
            ->first();

        if ($bucket === null) {
            return;
        }

        $ledgerQty = (float) $bucket->stock;
        if (abs($ledgerQty - (float) $row->stock_quantity) > 0.0001) {
            $row->stock_quantity = $ledgerQty;
            $row->inventory_value = round($ledgerQty * (float) $row->average_cost, 4);
            $row->save();
        }
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
