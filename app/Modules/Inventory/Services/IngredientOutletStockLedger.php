<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Inventory\Domain\StockMovement;
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
    ): StockMovement {
        abort_if($outletId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outlet_id is required for stock movements.');
        abort_if($quantity <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'quantity must be positive.');

        $sign = match ($type) {
            'purchase', 'adjustment' => 1,
            'sale', 'waste' => -1,
            default => null,
        };
        abort_if($sign === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid movement type.');

        return DB::transaction(function () use ($outletId, $ingredientId, $type, $quantity, $sign, $sourceType, $sourceId): StockMovement {
            Ingredient::query()->lockForUpdate()->findOrFail($ingredientId);

            /** @var InventoryStock $bucket */
            $bucket = InventoryStock::query()->lockForUpdate()->firstOrCreate(
                ['ingredient_id' => $ingredientId, 'outlet_id' => $outletId],
                ['stock' => 0]
            );

            $nextStock = (float) $bucket->stock + ($sign * $quantity);
            abort_if(
                $nextStock < 0,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Insufficient stock for this outlet.'
            );

            $bucket->update(['stock' => $nextStock]);

            return StockMovement::query()->create([
                'inventory_item_id' => $ingredientId,
                'outlet_id' => $outletId,
                'type' => $type,
                'quantity' => $quantity,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);
        });
    }
}
