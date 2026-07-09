<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Orders\Domain\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DailyStocktakeTheoreticalUsageService
{
    /** @return array<int, float> ingredient_id => qty */
    public function usageForBusinessDate(int $outletId, string $businessDate): array
    {
        $orderIds = Order::query()
            ->where('outlet_id', $outletId)
            ->where('payment_status', 'paid')
            ->whereDate('updated_at', $businessDate)
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return [];
        }

        $items = DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->whereNotNull('item_id')
            ->get(['item_id', 'qty']);

        if ($items->isEmpty()) {
            return [];
        }

        $menuIds = $items->pluck('item_id')->unique()->values()->all();
        $recipes = DB::table('menu_recipes')
            ->whereIn('menu_item_id', $menuIds)
            ->get(['menu_item_id', 'inventory_item_id', 'quantity'])
            ->groupBy('menu_item_id');

        $usage = [];
        foreach ($items as $item) {
            $menuId = (int) $item->item_id;
            if (! isset($recipes[$menuId])) {
                continue;
            }
            foreach ($recipes[$menuId] as $recipe) {
                $ingredientId = (int) $recipe->inventory_item_id;
                $usage[$ingredientId] = ($usage[$ingredientId] ?? 0)
                    + ((float) $item->qty * (float) $recipe->quantity);
            }
        }

        return $usage;
    }
}
