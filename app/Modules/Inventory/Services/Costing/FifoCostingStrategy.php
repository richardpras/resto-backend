<?php

namespace App\Modules\Inventory\Services\Costing;

use App\Models\Modules\Inventory\Domain\InventoryCostLayer;
use App\Models\Modules\Inventory\Domain\InventoryValuation;
use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryValuationAuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class FifoCostingStrategy implements InventoryCostingStrategy
{
    public function __construct(
        private readonly InventoryValuationRowSupport $rowSupport,
        private readonly InventoryValuationAuditService $auditService,
    ) {}

    public function getUnitCost(int $ingredientId, int $outletId): float
    {
        $layer = InventoryCostLayer::query()
            ->where('ingredient_id', $ingredientId)
            ->where('outlet_id', $outletId)
            ->where('qty_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->first();

        if ($layer !== null) {
            return (float) $layer->unit_cost;
        }

        $row = InventoryValuation::query()
            ->where('ingredient_id', $ingredientId)
            ->where('outlet_id', $outletId)
            ->first();

        if ($row !== null && (float) $row->last_purchase_cost > 0) {
            return (float) $row->last_purchase_cost;
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

        return DB::transaction(function () use ($ingredientId, $outletId, $qty, $unitCost, $grnId, $actor, $sourceMovementId): InventoryValuation {
            $row = $this->rowSupport->lockOrCreate($ingredientId, $outletId);

            if ($sourceMovementId !== null) {
                InventoryCostLayer::query()->firstOrCreate(
                    [
                        'ingredient_id' => $ingredientId,
                        'outlet_id' => $outletId,
                        'source_movement_id' => $sourceMovementId,
                    ],
                    [
                        'grn_id' => $grnId,
                        'qty_received' => $qty,
                        'qty_remaining' => $qty,
                        'unit_cost' => round($unitCost, 4),
                        'received_at' => now(),
                    ],
                );
            } else {
                InventoryCostLayer::query()->create([
                    'ingredient_id' => $ingredientId,
                    'outlet_id' => $outletId,
                    'source_movement_id' => null,
                    'grn_id' => $grnId,
                    'qty_received' => $qty,
                    'qty_remaining' => $qty,
                    'unit_cost' => round($unitCost, 4),
                    'received_at' => now(),
                ]);
            }

            $this->syncValuationFromLayers($row, $unitCost, $grnId);
            $this->rowSupport->syncStockQuantityFromLedger($ingredientId, $outletId, $row, fifoDisplayAverage: true);

            $this->auditService->log(
                'inventory_fifo_layer_created',
                (int) $row->id,
                $outletId,
                $actor,
                [
                    'ingredientId' => $ingredientId,
                    'receivedQty' => $qty,
                    'unitCost' => $unitCost,
                    'grnId' => $grnId,
                    'sourceMovementId' => $sourceMovementId,
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
            $unitCost = $this->consumeLayers($ingredientId, $outletId, $qty);
            $this->syncValuationFromLayers($row);
            $row->last_updated_at = now();
            $row->save();

            $this->rowSupport->syncStockQuantityFromLedger($ingredientId, $outletId, $row, fifoDisplayAverage: true);

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
                    'costingMethod' => 'fifo',
                ],
            );

            return $unitCost;
        });
    }

    public function rebuildPair(int $ingredientId, int $outletId, ?User $actor, Collection $movements): void
    {
        DB::transaction(function () use ($ingredientId, $outletId, $actor, $movements): void {
            InventoryCostLayer::query()
                ->where('ingredient_id', $ingredientId)
                ->where('outlet_id', $outletId)
                ->delete();

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
                    InventoryCostLayer::query()->create([
                        'ingredient_id' => $ingredientId,
                        'outlet_id' => $outletId,
                        'source_movement_id' => (int) $movement->id,
                        'grn_id' => null,
                        'qty_received' => $qty,
                        'qty_remaining' => $qty,
                        'unit_cost' => round($unitCost, 4),
                        'received_at' => $movement->created_at ?? now(),
                    ]);
                    if ((string) $movement->type === 'purchase') {
                        $row->last_purchase_cost = round($unitCost, 4);
                    }
                } elseif (in_array((string) $movement->type, ['sale', 'waste'], true)) {
                    $this->consumeLayers($ingredientId, $outletId, $qty);
                }
            }

            $this->syncValuationFromLayers($row);
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
                    'costingMethod' => 'fifo',
                ],
            );
        });
    }

    private function consumeLayers(
        int $ingredientId,
        int $outletId,
        float $qty,
    ): float {
        $remaining = $qty;
        $totalCost = 0.0;

        $layers = InventoryCostLayer::query()
            ->where('ingredient_id', $ingredientId)
            ->where('outlet_id', $outletId)
            ->where('qty_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }

            $available = (float) $layer->qty_remaining;
            $take = min($remaining, $available);
            $totalCost += $take * (float) $layer->unit_cost;
            $layer->qty_remaining = round($available - $take, 4);
            $layer->save();
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $fallback = $this->getUnitCost($ingredientId, $outletId);
            if ($fallback <= 0) {
                $row = InventoryValuation::query()
                    ->where('ingredient_id', $ingredientId)
                    ->where('outlet_id', $outletId)
                    ->first();
                $fallback = (float) ($row?->last_purchase_cost ?? 0);
            }
            $totalCost += $remaining * $fallback;
        }

        return $qty > 0 ? round($totalCost / $qty, 4) : 0.0;
    }

    private function syncValuationFromLayers(InventoryValuation $row, ?float $lastPurchaseCost = null, ?int $grnId = null): void
    {
        $layers = InventoryCostLayer::query()
            ->where('ingredient_id', (int) $row->ingredient_id)
            ->where('outlet_id', (int) $row->outlet_id)
            ->where('qty_remaining', '>', 0)
            ->get();

        $qty = round((float) $layers->sum('qty_remaining'), 4);
        $value = round($layers->sum(static fn (InventoryCostLayer $layer): float => (float) $layer->qty_remaining * (float) $layer->unit_cost), 4);

        $row->stock_quantity = $qty;
        $row->inventory_value = $value;
        $row->average_cost = $qty > 0 ? round($value / $qty, 4) : 0.0;
        if ($lastPurchaseCost !== null) {
            $row->last_purchase_cost = round($lastPurchaseCost, 4);
        }
        if ($grnId !== null) {
            $row->last_grn_id = $grnId;
        }
        $row->last_updated_at = now();
        $row->save();
    }
}
