<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuRecipeCostSetting;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class TheoreticalFoodCostTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_theoretical_food_cost_percent(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 200000);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 100000);
        MenuRecipeCostSetting::query()->create([
            'menu_item_id' => $menu['menuId'],
            'yield_percent' => 80,
            'waste_percent' => 5,
            'is_active' => true,
        ]);

        $result = app(RecipeCostService::class)->calculateTheoreticalFoodCost($menu['menuId'], (int) $outlet->id);

        $this->assertSame(200000.0, $result['sellingPrice']);
        $this->assertSame(131250.0, $result['finalTheoreticalCost']);
        $this->assertSame(65.625, $result['theoreticalFoodCostPercent']);
    }
}
