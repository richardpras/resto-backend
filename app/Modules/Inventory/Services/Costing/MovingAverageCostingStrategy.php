<?php

namespace App\Modules\Inventory\Services\Costing;

use App\Models\Modules\Inventory\Domain\InventoryValuation;
use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryValuationAuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class MovingAverageCostingStrategy implements InventoryCostingStrategy
{
    public function __construct(
        private readonly InventoryValuationRowSupport $rowSupport,
        private readonly InventoryValuationAuditService $auditService,
    ) {}

    public function getUnitCost(int $ingredientId, int $outletId): float
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

    public function getInventoryValue(int $ingredientId, int $outletId): array
    {
        $row = InventoryValuation::query()
            ->where('ingredient_id', $ingredientId)
            ->where('outlet_id', $outletId)
            ->first();

        return $this->rowSupport->mapValuationRow($row);
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
        abort_if($qty <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Purchase quantity must be positive.');
        abort_if($unitCost < 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Purchase unit cost cannot be negative.');

        return DB::transaction(function () use ($ingredientId, $outletId, $qty, $unitCost, $grnId, $actor): InventoryValuation {
            $row = $this->rowSupport->lockOrCreate($ingredientId, $outletId);
            $this->applyPurchaseToRow($row, $qty, $unitCost);
            $row->last_purchase_cost = round($unitCost, 4);
            $row->last_grn_id = $grnId;
            $row->last_updated_at = now();
            $row->save();

            $this->rowSupport->syncStockQuantityFromLedger($ingredientId, $outletId, $row);

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
            $row = $this->rowSupport->lockOrCreate($ingredientId, $outletId);
            $unitCost = (float) $row->average_cost;
            $this->applyConsumptionToRow($row, $qty);
            $row->last_updated_at = now();
            $row->save();

            $this->rowSupport->syncStockQuantityFromLedger($ingredientId, $outletId, $row);

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

    public function rebuildPair(int $ingredientId, int $outletId, ?User $actor, Collection $movements): void
    {
        DB::transaction(function () use ($ingredientId, $outletId, $actor, $movements): void {
            $row = $this->rowSupport->lockOrCreate($ingredientId, $outletId);
            $row->fill([
                'stock_quantity' => 0,
                'inventory_value' => 0,
                'average_cost' => 0,
                'last_purchase_cost' => 0,
                'last_grn_id' => null,
                'last_updated_at' => now(),
            ]);
            $row->save();

            foreach ($movements as $movement) {
                /** @var StockMovement $movement */
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
                    'costingMethod' => 'moving_average',
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
}
