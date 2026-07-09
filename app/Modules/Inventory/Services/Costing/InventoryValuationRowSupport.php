<?php

namespace App\Modules\Inventory\Services\Costing;

use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Inventory\Domain\InventoryValuation;

final class InventoryValuationRowSupport
{
    public function lockOrCreate(int $ingredientId, int $outletId): InventoryValuation
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

    public function syncStockQuantityFromLedger(int $ingredientId, int $outletId, InventoryValuation $row, bool $fifoDisplayAverage = false): void
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
            if (! $fifoDisplayAverage) {
                $row->inventory_value = round($ledgerQty * (float) $row->average_cost, 4);
            }
            $row->save();
        }
    }

    /** @return array{stockQuantity: float, inventoryValue: float, averageCost: float, lastPurchaseCost: float, lastUpdatedAt: ?string} */
    public function mapValuationRow(?InventoryValuation $row): array
    {
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
}
