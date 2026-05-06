<?php

namespace Database\Seeders;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuRecipe;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Seeds ingredients, menu items, and recipes from {@see database/data/template_inventory_menu.json}
 * (parity with template/src/stores/inventoryStore.ts + template POS menu).
 */
class TemplateInventoryMenuSeeder extends Seeder
{
    public function run(): void
    {
        if (Ingredient::query()->exists() || MenuItem::query()->exists()) {
            return;
        }

        $path = database_path('data/template_inventory_menu.json');
        $data = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, int> $ingredientMap legacy_id -> DB id */
        $ingredientMap = [];

        foreach ($data['ingredients'] as $row) {
            $ing = Ingredient::query()->create([
                'tenant_id' => null,
                'outlet_id' => null,
                'name' => $row['name'],
                'type' => $row['type'],
                'unit' => $row['unit'],
                'stock' => $row['stock'],
                'min' => $row['min'],
                'price' => $row['price'] ?? null,
                'notes' => $row['notes'] ?? null,
            ]);
            $ingredientMap[$row['legacy_id']] = $ing->id;
        }

        /** @var array<int, int> $menuMap template numeric id (1..16) -> DB id */
        $menuMap = [];

        foreach ($data['menu_items'] as $row) {
            $m = MenuItem::query()->create([
                'tenant_id' => null,
                'outlet_id' => null,
                'name' => $row['name'],
                'category' => $row['category'],
                'emoji' => $row['emoji'],
                'price' => $row['price'],
                'available' => true,
            ]);
            $menuMap[(int) $row['legacy_num_id']] = $m->id;
        }

        foreach ($data['recipes'] as $recipe) {
            $menuId = $menuMap[(int) $recipe['menu_legacy_num_id']] ?? null;
            if ($menuId === null) {
                continue;
            }
            foreach ($recipe['lines'] as $line) {
                $ingId = $ingredientMap[$line['ingredient_legacy_id']] ?? null;
                if ($ingId === null) {
                    continue;
                }
                MenuRecipe::query()->firstOrCreate(
                    [
                        'menu_item_id' => $menuId,
                        'inventory_item_id' => $ingId,
                    ],
                    ['quantity' => $line['qty']],
                );
            }
        }
    }
}
