<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use Illuminate\Support\Facades\DB;

final class MenuPopularityService
{
    public function calculatePopularity(
        int $menuItemId,
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): float {
        $quantities = $this->loadSalesQuantities($outletId, $fromDate, $toDate);

        return (float) ($quantities[$menuItemId] ?? 0);
    }

    public function calculatePopularityPercent(
        int $menuItemId,
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): float {
        $quantities = $this->loadSalesQuantities($outletId, $fromDate, $toDate);
        $total = array_sum($quantities);
        if ($total <= 0) {
            return 0.0;
        }

        return round(((float) ($quantities[$menuItemId] ?? 0) / $total) * 100, 4);
    }

    /** @return array<int,array<string,mixed>> */
    public function getTopPopularItems(
        int $outletId,
        int $limit = 10,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        return $this->rankByPopularity($outletId, $limit, descending: true, fromDate: $fromDate, toDate: $toDate);
    }

    /** @return array<int,array<string,mixed>> */
    public function getLeastPopularItems(
        int $outletId,
        int $limit = 10,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        return $this->rankByPopularity($outletId, $limit, descending: false, fromDate: $fromDate, toDate: $toDate);
    }

    /** @return array<int,float> menuItemId => quantity */
    public function loadSalesQuantities(int $outletId, ?string $fromDate, ?string $toDate): array
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.outlet_id', $outletId)
            ->where('orders.payment_status', 'paid')
            ->whereNotNull('order_items.item_id');

        if ($fromDate) {
            $query->whereDate('orders.created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('orders.created_at', '<=', $toDate);
        }

        $rows = $query
            ->selectRaw('order_items.item_id as menu_item_id')
            ->selectRaw('SUM(order_items.qty) as total_qty')
            ->groupBy('order_items.item_id')
            ->get();

        $quantities = [];
        foreach ($rows as $row) {
            $quantities[(int) $row->menu_item_id] = (float) $row->total_qty;
        }

        return $quantities;
    }

    /** @return array<int,array<string,mixed>> */
    private function rankByPopularity(
        int $outletId,
        int $limit,
        bool $descending,
        ?string $fromDate,
        ?string $toDate,
    ): array {
        $quantities = $this->loadSalesQuantities($outletId, $fromDate, $toDate);
        $total = array_sum($quantities);

        $rows = [];
        foreach ($quantities as $menuItemId => $qty) {
            $rows[] = [
                'menuItemId' => (string) $menuItemId,
                'menuItemName' => MenuItem::query()->find((int) $menuItemId)?->name,
                'quantitySold' => $qty,
                'popularityPercent' => $total > 0 ? round(($qty / $total) * 100, 4) : 0.0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $descending
            ? $b['quantitySold'] <=> $a['quantitySold']
            : $a['quantitySold'] <=> $b['quantitySold']);

        return array_slice($rows, 0, max(1, $limit));
    }
}
