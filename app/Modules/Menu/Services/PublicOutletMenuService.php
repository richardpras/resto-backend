<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PublicOutletMenuService
{
    /**
     * @return Collection<int, MenuItem>
     */
    public function listForOutlet(Outlet $outlet): Collection
    {
        return MenuItem::query()
            ->with('menuCategory')
            ->where('available', true)
            ->whereHas(
                'outletMappings',
                fn ($mapping) => $mapping
                    ->where('outlet_id', (int) $outlet->id)
                    ->where('is_active', true)
            )
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, tax: float, total: float}
     */
    public function resolvePreorderTotals(Outlet $outlet, array $items): array
    {
        if ($items === []) {
            return ['items' => [], 'subtotal' => 0.0, 'tax' => 0.0, 'total' => 0.0];
        }

        $resolved = [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            $menuItemId = (int) ($item['menuItemId'] ?? $item['id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            if ($menuItemId < 1 || $qty <= 0) {
                throw (new ModelNotFoundException)->setModel(MenuItem::class);
            }

            $menuItem = MenuItem::query()
                ->whereKey($menuItemId)
                ->where('available', true)
                ->whereHas(
                    'outletMappings',
                    fn ($mapping) => $mapping
                        ->where('outlet_id', (int) $outlet->id)
                        ->where('is_active', true)
                )
                ->first();

            if ($menuItem === null) {
                throw (new ModelNotFoundException)->setModel(MenuItem::class, [(string) $menuItemId]);
            }

            $price = (float) $menuItem->price;
            $lineTotal = round($price * $qty, 2);
            $subtotal += $lineTotal;
            $resolved[] = [
                'id' => (string) $menuItem->id,
                'name' => (string) $menuItem->name,
                'qty' => $qty,
                'price' => $price,
            ];
        }

        $subtotal = round($subtotal, 2);
        $tax = 0.0;
        $total = $subtotal;

        return [
            'items' => $resolved,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
        ];
    }
}
