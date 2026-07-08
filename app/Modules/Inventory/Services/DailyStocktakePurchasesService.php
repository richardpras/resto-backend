<?php

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;

final class DailyStocktakePurchasesService
{
    /** @return array<int, float> ingredient_id => qty */
    public function purchasesForBusinessDate(int $outletId, string $businessDate): array
    {
        $rows = DB::table('stock_movements')
            ->where('outlet_id', $outletId)
            ->where('type', 'purchase')
            ->whereDate('created_at', $businessDate)
            ->selectRaw('inventory_item_id, SUM(quantity) as total_qty')
            ->groupBy('inventory_item_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->inventory_item_id] = (float) $row->total_qty;
        }

        return $result;
    }
}
