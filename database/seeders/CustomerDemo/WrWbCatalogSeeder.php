<?php

namespace Database\Seeders\CustomerDemo;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuItemOutlet;
use App\Models\Modules\Menu\Domain\MenuRecipe;
use App\Models\Modules\Production\Domain\ProductionStation;
use Illuminate\Database\Seeder;

class WrWbCatalogSeeder extends Seeder
{
  /** @var array<string, list<string>> */
    private const INGREDIENTS = [
        'protein' => ['Ayam Fillet', 'Daging Sapi', 'Ikan Kakap', 'Tahu', 'Udang'],
        'vegetables' => ['Tomat', 'Selada', 'Bawang', 'Wortel', 'Paprika'],
        'dry' => ['Beras', 'Tepung', 'Gula', 'Garam', 'Minyak'],
        'beverage' => ['Kopi Bubuk', 'Teh', 'Susu', 'Sirup', 'Soda'],
        'packaging' => ['Box', 'Cup', 'Tissue', 'Sedotan', 'Kantong'],
    ];

    /** @var array<string, list<array{name: string, price: int}>> */
    private const MENUS = [
        'Food' => [
            ['name' => 'Nasi Goreng Spesial', 'price' => 35000],
            ['name' => 'Ayam Bakar Madu', 'price' => 42000],
            ['name' => 'Soto Ayam', 'price' => 28000],
            ['name' => 'Mie Goreng', 'price' => 32000],
            ['name' => 'Rendang Daging', 'price' => 55000],
        ],
        'Beverage' => [
            ['name' => 'Es Teh Manis', 'price' => 12000],
            ['name' => 'Kopi Susu', 'price' => 18000],
            ['name' => 'Jus Alpukat', 'price' => 22000],
            ['name' => 'Lemon Tea', 'price' => 15000],
            ['name' => 'Air Mineral', 'price' => 8000],
        ],
        'Dessert' => [
            ['name' => 'Pisang Goreng', 'price' => 15000],
            ['name' => 'Es Krim Vanilla', 'price' => 18000],
            ['name' => 'Brownies Coklat', 'price' => 20000],
            ['name' => 'Puding Karamel', 'price' => 16000],
            ['name' => 'Klepon', 'price' => 12000],
        ],
    ];

    public function run(): void
    {
        $outletId = CustomerDemoContext::outletId();
        $ingredientIds = $this->seedIngredients($outletId);
        $this->seedMenus($outletId, $ingredientIds);
    }

    /** @return list<int> */
    private function seedIngredients(int $outletId): array
    {
        $ids = [];
        $index = 0;

        foreach (self::INGREDIENTS as $group => $names) {
            foreach ($names as $name) {
                $index++;
                $stock = 80 + ($index * 3);

                $ingredient = Ingredient::query()->updateOrCreate(
                    ['outlet_id' => $outletId, 'name' => $name],
                    [
                        'tenant_id' => CustomerDemoContext::TENANT_ID,
                        'type' => 'ingredient',
                        'unit' => 'kg',
                        'stock' => $stock,
                        'min' => 10,
                        'price' => 8000 + ($index * 500),
                        'notes' => "WR WB {$group}",
                    ],
                );

                InventoryStock::query()->updateOrCreate(
                    ['ingredient_id' => $ingredient->id, 'outlet_id' => $outletId],
                    ['stock' => $stock],
                );

                $ids[] = (int) $ingredient->id;
            }
        }

        return $ids;
    }

    /** @param list<int> $ingredientIds */
    private function seedMenus(int $outletId, array $ingredientIds): void
    {
        $stationIds = ProductionStation::query()
            ->where('outlet_id', $outletId)
            ->pluck('id', 'code');

        $categoryIds = MenuCategory::query()
            ->whereIn('code', ['WRWB-FOOD', 'WRWB-BEVERAGE', 'WRWB-DESSERT'])
            ->pluck('id', 'name');

        $menuIndex = 0;
        foreach (self::MENUS as $categoryName => $items) {
            $stationCode = match ($categoryName) {
                'Beverage' => 'bar',
                default => 'kitchen',
            };
            $stationId = $stationIds->get($stationCode);

            foreach ($items as $row) {
                $menuIndex++;
                $item = MenuItem::query()->updateOrCreate(
                    ['outlet_id' => $outletId, 'name' => $row['name']],
                    [
                        'tenant_id' => CustomerDemoContext::TENANT_ID,
                        'category' => $categoryName,
                        'menu_category_id' => $categoryIds->get($categoryName),
                        'production_station_id' => $stationId,
                        'emoji' => $categoryName === 'Beverage' ? '☕' : '🍽️',
                        'price' => $row['price'],
                        'available' => true,
                    ],
                );

                MenuItemOutlet::query()->updateOrCreate(
                    ['menu_item_id' => $item->id, 'outlet_id' => (string) $outletId],
                    ['is_active' => true, 'price_override' => $row['price']],
                );

                $recipeIngredients = [
                    $ingredientIds[$menuIndex % count($ingredientIds)],
                    $ingredientIds[($menuIndex + 3) % count($ingredientIds)],
                    $ingredientIds[($menuIndex + 7) % count($ingredientIds)],
                ];

                foreach ($recipeIngredients as $i => $ingredientId) {
                    MenuRecipe::query()->updateOrCreate(
                        ['menu_item_id' => $item->id, 'inventory_item_id' => $ingredientId],
                        ['quantity' => 0.1 + ($i * 0.05)],
                    );
                }
            }
        }
    }
}
