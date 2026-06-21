<?php

namespace Database\Seeders\Demo;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuItemOutlet;
use App\Models\Modules\Menu\Domain\MenuRecipe;
use App\Models\Modules\Production\Domain\ProductionStation;
use Database\Seeders\Support\DemoDatasetSeederService;
use Illuminate\Database\Seeder;

class DemoCatalogSeeder extends Seeder
{
    private const CATEGORIES = [
        'protein' => ['Chicken Breast', 'Beef Sirloin', 'Salmon Fillet', 'Tofu', 'Shrimp'],
        'vegetables' => ['Tomato', 'Lettuce', 'Onion', 'Carrot', 'Bell Pepper'],
        'dry_goods' => ['Flour', 'Rice', 'Pasta', 'Sugar', 'Salt'],
        'beverage' => ['Coffee Beans', 'Tea Leaves', 'Milk', 'Syrup', 'Soda Water'],
        'packaging' => ['Paper Box', 'Plastic Cup', 'Napkin', 'Straw', 'Bag'],
    ];

    private const MENU_CATEGORIES = ['Food', 'Beverage', 'Dessert'];

    public function run(): void
    {
        DemoDatasetSeederService::seedMenuAndInventory();
        $this->expandInventory();
        $this->expandMenu();
        $this->seedPromoVouchers();
    }

    private function expandInventory(): void
    {
        foreach (DemoSeederContext::outlets() as $outlet) {
            $index = 0;
            foreach (self::CATEGORIES as $category => $names) {
                foreach ($names as $name) {
                    $index++;
                    $suffix = $index < 10 ? "0{$index}" : (string) $index;
                    $itemName = "{$name} {$suffix}";
                    $stock = 5 + ($index % 40);
                    $min = 10 + ($index % 8);

                    $ingredient = Ingredient::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'name' => $itemName],
                        [
                            'tenant_id' => 1,
                            'type' => 'ingredient',
                            'unit' => 'kg',
                            'stock' => $stock,
                            'min' => $min,
                            'price' => 10000 + ($index * 500),
                            'notes' => "Demo {$category}",
                        ],
                    );

                    InventoryStock::query()->updateOrCreate(
                        ['ingredient_id' => $ingredient->id, 'outlet_id' => $outlet->id],
                        ['stock' => $stock],
                    );

                    if ($index % 17 === 0) {
                        StockMovement::query()->updateOrCreate(
                            [
                                'inventory_item_id' => $ingredient->id,
                                'outlet_id' => $outlet->id,
                                'source_type' => 'demo_adjustment',
                                'source_id' => (string) $ingredient->id,
                            ],
                            [
                                'type' => 'adjustment',
                                'quantity' => -2,
                                'unit_cost' => 10000,
                                'total_cost' => 20000,
                                'ledger_payload' => ['reason' => 'Demo stock adjustment'],
                            ],
                        );
                    }
                }
            }
        }
    }

    private function expandMenu(): void
    {
        foreach (DemoSeederContext::outlets() as $outlet) {
            $ingredients = Ingredient::query()->where('outlet_id', $outlet->id)->pluck('id')->all();
            if ($ingredients === []) {
                continue;
            }

            $stationIdsByCode = ProductionStation::query()
                ->where('outlet_id', $outlet->id)
                ->pluck('id', 'code');
            $stationForCategory = static function (string $category) use ($stationIdsByCode): ?int {
                $code = match ($category) {
                    'Beverage' => 'bar',
                    'Dessert' => $stationIdsByCode->has('dessert') ? 'dessert' : 'kitchen',
                    default => 'kitchen',
                };

                return $stationIdsByCode->get($code) !== null ? (int) $stationIdsByCode->get($code) : null;
            };

            for ($i = 1; $i <= 44; $i++) {
                $category = self::MENU_CATEGORIES[$i % count(self::MENU_CATEGORIES)];
                $price = 15000 + ($i * 2500);
                $cost = (int) ($price * (0.35 + (($i % 4) * 0.05)));

                $item = MenuItem::query()->updateOrCreate(
                    ['outlet_id' => $outlet->id, 'name' => "Demo {$category} Item {$i}"],
                    [
                        'tenant_id' => 1,
                        'category' => $category,
                        'production_station_id' => $stationForCategory($category),
                        'emoji' => $category === 'Beverage' ? '☕' : '🍽️',
                        'price' => $price,
                        'available' => true,
                    ],
                );

                MenuItemOutlet::query()->updateOrCreate(
                    ['menu_item_id' => $item->id, 'outlet_id' => (string) $outlet->id],
                    ['is_active' => true, 'price_override' => $price],
                );

                $ingredientId = $ingredients[$i % count($ingredients)];
                MenuRecipe::query()->updateOrCreate(
                    ['menu_item_id' => $item->id, 'inventory_item_id' => $ingredientId],
                    ['quantity' => 0.1 + ($i % 5) * 0.05],
                );

                unset($cost);
            }
        }
    }

    private function seedPromoVouchers(): void
    {
        foreach (DemoSeederContext::outlets() as $outlet) {
            $vouchers = [
                ['code' => 'WELCOME10', 'name' => 'Welcome 10%', 'type' => LoyaltyVoucher::VALUE_PERCENTAGE, 'value' => 10],
                ['code' => 'FLAT20K', 'name' => 'Flat Rp 20.000', 'type' => LoyaltyVoucher::VALUE_FIXED_AMOUNT, 'value' => 20000],
                ['code' => 'COFFEE2X1', 'name' => 'Buy Coffee Get 1', 'type' => LoyaltyVoucher::VALUE_FREE_ITEM, 'value' => 0],
                ['code' => 'HAPPYHOUR', 'name' => 'Happy Hour 15%', 'type' => LoyaltyVoucher::VALUE_PERCENTAGE, 'value' => 15],
            ];

            foreach ($vouchers as $v) {
                LoyaltyVoucher::query()->updateOrCreate(
                    ['outlet_id' => $outlet->id, 'code' => $v['code']],
                    [
                        'name' => $v['name'],
                        'description' => 'Demo promotion voucher',
                        'voucher_type' => LoyaltyVoucher::TYPE_CAMPAIGN,
                        'value_type' => $v['type'],
                        'value' => $v['value'],
                        'minimum_spend' => 50000,
                        'valid_from' => now()->subMonth(),
                        'valid_until' => now()->addMonths(3),
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
