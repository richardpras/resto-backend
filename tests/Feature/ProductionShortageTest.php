<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\ProductionShortageService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ProductionShortageTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_detect_shortages_classifies_stock_status(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, stock: 5);
        DB::table('inventory_stocks')->updateOrInsert(
            ['ingredient_id' => $ingredient->id, 'outlet_id' => $outlet->id],
            ['stock' => 5, 'created_at' => now(), 'updated_at' => now()],
        );
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $result = app(ProductionShortageService::class)->detectShortages($outlet->id, [
            ['menuItemId' => $menu['menuId'], 'quantity' => 10],
        ]);

        $row = $result['shortages'][0];
        $this->assertSame(10.0, (float) $row['requiredQuantity']);
        $this->assertSame(5.0, (float) $row['shortageQuantity']);
        $this->assertSame(ProductionShortageService::STATUS_SHORTAGE, $row['status']);
    }
}
