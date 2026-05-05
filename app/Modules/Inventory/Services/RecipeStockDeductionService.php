<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\Modules\Orders\Domain\Order;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RecipeStockDeductionService
{
    public function deductForPaidOrder(Order $order): void
    {
        if ($order->stock_deducted_at !== null) {
            return;
        }

        $order->loadMissing('items');
        $requiredByIngredient = [];
        $menuIds = $order->items->pluck('item_id')->filter()->values()->all();
        if ($menuIds === []) {
            $order->update(['stock_deducted_at' => now()]);

            return;
        }

        $recipes = DB::table('menu_recipes')
            ->whereIn('menu_item_id', $menuIds)
            ->get()
            ->groupBy('menu_item_id');

        foreach ($order->items as $item) {
            if ($item->item_id === null || ! isset($recipes[$item->item_id])) {
                continue;
            }

            foreach ($recipes[$item->item_id] as $recipe) {
                $requiredByIngredient[$recipe->inventory_item_id] = ($requiredByIngredient[$recipe->inventory_item_id] ?? 0)
                    + ((float) $item->qty * (float) $recipe->quantity);
            }
        }

        foreach ($requiredByIngredient as $ingredientId => $requiredQty) {
            $ingredient = Ingredient::query()->lockForUpdate()->find($ingredientId);
            abort_if($ingredient === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Recipe ingredient not found.');

            $nextStock = (float) $ingredient->stock - $requiredQty;
            abort_if(
                $nextStock < 0,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                "Insufficient stock for ingredient {$ingredient->name}."
            );

            $ingredient->update(['stock' => $nextStock]);

            StockMovement::query()->create([
                'inventory_item_id' => $ingredient->id,
                'type' => 'sale',
                'quantity' => $requiredQty,
                'source_type' => 'order_payment',
                'source_id' => $order->code,
            ]);
        }

        $order->update(['stock_deducted_at' => now()]);
    }
}
