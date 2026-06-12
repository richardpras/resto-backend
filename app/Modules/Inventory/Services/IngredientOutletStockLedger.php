<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Inventory\Domain\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * All stock changes go through inventory_stocks + stock_movements (per numeric outlet_id).
 */
final class IngredientOutletStockLedger
{
    public function apply(
        int $outletId,
        int $ingredientId,
        string $type,
        float $quantity,
        string $sourceType,
        ?string $sourceId = null,
        ?array $ledgerPayload = null,
        bool $enforceNonNegative = true,
    ): StockMovement {
        abort_if($outletId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outlet_id is required for stock movements.');
        abort_if($quantity <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'quantity must be positive.');

        $sign = match ($type) {
            'purchase', 'adjustment' => 1,
            'sale', 'waste' => -1,
            default => null,
        };
        abort_if($sign === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid movement type.');

        return DB::transaction(function () use ($outletId, $ingredientId, $type, $quantity, $sign, $sourceType, $sourceId, $ledgerPayload, $enforceNonNegative): StockMovement {
            /** @var Ingredient $ingredient */
            $ingredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredientId);
            abort_if(
                (string) $ingredient->type !== 'ingredient',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Only ingredient type inventory can post stock movements.'
            );

            if ($sourceId !== null && $sourceType === 'order_payment') {
                $existing = StockMovement::query()
                    ->where('outlet_id', $outletId)
                    ->where('inventory_item_id', $ingredientId)
                    ->where('type', $type)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            /** @var InventoryStock $bucket */
            $bucket = InventoryStock::query()
                ->where('ingredient_id', $ingredientId)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->first();
            if ($bucket === null) {
                try {
                    $bucket = InventoryStock::query()->create([
                        'ingredient_id' => $ingredientId,
                        'outlet_id' => $outletId,
                        'stock' => 0,
                    ]);
                } catch (QueryException) {
                    $bucket = InventoryStock::query()
                        ->where('ingredient_id', $ingredientId)
                        ->where('outlet_id', $outletId)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            $nextStock = (float) $bucket->stock + ($sign * $quantity);
            if ($enforceNonNegative) {
                abort_if(
                    $nextStock < 0,
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'Insufficient stock for this outlet.'
                );
            }

            $bucket->update(['stock' => $nextStock]);
            $unitCost = isset($ledgerPayload['unit_cost']) ? (float) $ledgerPayload['unit_cost'] : (float) ($ingredient->price ?? 0);
            $totalCost = $unitCost * $quantity;

            return StockMovement::query()->create([
                'inventory_item_id' => $ingredientId,
                'outlet_id' => $outletId,
                'type' => $type,
                'quantity' => $quantity,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'ledger_payload' => $ledgerPayload,
            ]);
        });
    }
}
