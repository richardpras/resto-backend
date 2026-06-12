<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Orders\Domain\Order;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use Illuminate\Support\Facades\DB;

class OrderStockValidationService
{
    public function __construct(
        private readonly InventorySalePolicyService $inventorySalePolicyService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function collectShortages(int $outletId, array $items): array
    {
        if ($outletId < 1 || $items === []) {
            return [];
        }

        $menuIds = collect($items)
            ->map(fn (array $item): int => (int) ($item['id'] ?? $item['item_id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($menuIds === []) {
            return [];
        }

        $recipes = DB::table('menu_recipes')
            ->whereIn('menu_item_id', $menuIds)
            ->get()
            ->groupBy('menu_item_id');

        $requiredByIngredient = [];
        $menuMeta = [];

        foreach ($items as $item) {
            $menuItemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            if ($menuItemId < 1) {
                continue;
            }
            $qty = (float) ($item['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $menuMeta[$menuItemId] = [
                'name' => (string) ($item['name'] ?? 'Item'),
                'qty' => $qty,
            ];
            if (! isset($recipes[$menuItemId])) {
                continue;
            }
            foreach ($recipes[$menuItemId] as $recipe) {
                $ingredientId = (int) $recipe->inventory_item_id;
                $requiredByIngredient[$ingredientId] = ($requiredByIngredient[$ingredientId] ?? 0)
                    + ($qty * (float) $recipe->quantity);
            }
        }

        if ($requiredByIngredient === []) {
            return [];
        }

        $stocks = InventoryStock::query()
            ->where('outlet_id', $outletId)
            ->whereIn('ingredient_id', array_keys($requiredByIngredient))
            ->get()
            ->keyBy('ingredient_id');

        $shortages = [];
        foreach ($requiredByIngredient as $ingredientId => $requiredQty) {
            $available = (float) ($stocks->get((int) $ingredientId)?->stock ?? 0);
            if ($available + 0.00001 >= $requiredQty) {
                continue;
            }

            foreach ($menuMeta as $menuItemId => $meta) {
                if (! isset($recipes[$menuItemId])) {
                    continue;
                }
                foreach ($recipes[$menuItemId] as $recipe) {
                    if ((int) $recipe->inventory_item_id !== (int) $ingredientId) {
                        continue;
                    }
                    $perUnit = (float) $recipe->quantity;
                    if ($perUnit <= 0) {
                        continue;
                    }
                    $maxServings = (int) floor($available / $perUnit);
                    $shortages[] = [
                        'menuItemId' => (int) $menuItemId,
                        'name' => $meta['name'],
                        'requested' => (float) $meta['qty'],
                        'available' => max(0, $maxServings),
                        'outletId' => $outletId,
                        'ingredientId' => (int) $ingredientId,
                    ];
                    break 2;
                }
            }
        }

        return $this->dedupeShortagesByMenuItem($shortages);
    }

    /**
     * @param  list<array<string, mixed>>  $shortages
     * @return list<array<string, mixed>>
     */
    private function dedupeShortagesByMenuItem(array $shortages): array
    {
        $byMenuItem = [];
        foreach ($shortages as $row) {
            $menuItemId = (int) ($row['menuItemId'] ?? 0);
            if ($menuItemId < 1) {
                continue;
            }
            if (
                ! isset($byMenuItem[$menuItemId])
                || (int) ($row['available'] ?? 0) < (int) ($byMenuItem[$menuItemId]['available'] ?? 0)
            ) {
                $byMenuItem[$menuItemId] = $row;
            }
        }

        return array_values($byMenuItem);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function assertForSaleItems(int $outletId, array $items, ?Order $order = null): void
    {
        if (! $this->inventorySalePolicyService->enforceStockOnSale($outletId)) {
            return;
        }

        $shortages = $this->collectShortages($outletId, $items);
        if ($shortages === []) {
            return;
        }

        throw new InsufficientStockException(
            $shortages,
            $order !== null ? (int) $order->id : null,
            $order !== null ? (string) $order->code : null,
        );
    }
}
