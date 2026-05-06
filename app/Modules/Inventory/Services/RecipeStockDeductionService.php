<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Orders\Domain\Order;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RecipeStockDeductionService
{
    public function __construct(
        private readonly IngredientOutletStockLedger $ingredientOutletStockLedger,
    ) {}

    public function deductForPaidOrder(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            /** @var Order|null $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            abort_if($locked === null, Response::HTTP_NOT_FOUND, 'Order not found.');

            if ($locked->stock_deducted_at !== null) {
                return;
            }

            $locked->loadMissing('items');
            $requiredByIngredient = [];
            $menuIds = $locked->items->pluck('item_id')->filter()->values()->all();
            if ($menuIds === []) {
                $locked->update(['stock_deducted_at' => now()]);

                return;
            }

            $outletId = $locked->outlet_id;
            abort_if(
                $outletId === null || (int) $outletId < 1,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Order outlet_id is required for stock deduction.'
            );

            $recipes = DB::table('menu_recipes')
                ->whereIn('menu_item_id', $menuIds)
                ->get()
                ->groupBy('menu_item_id');

            foreach ($locked->items as $item) {
                if ($item->item_id === null || ! isset($recipes[$item->item_id])) {
                    continue;
                }

                foreach ($recipes[$item->item_id] as $recipe) {
                    $requiredByIngredient[$recipe->inventory_item_id] = ($requiredByIngredient[$recipe->inventory_item_id] ?? 0)
                        + ((float) $item->qty * (float) $recipe->quantity);
                }
            }

            $numericOutlet = (int) $outletId;

            foreach ($requiredByIngredient as $ingredientId => $requiredQty) {
                $this->ingredientOutletStockLedger->apply(
                    $numericOutlet,
                    (int) $ingredientId,
                    'sale',
                    $requiredQty,
                    'order_payment',
                    $locked->code
                );
            }

            $locked->update(['stock_deducted_at' => now()]);
        });
    }
}
