<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuRecipeCostSetting;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class RecipeCostCalculationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_full_recipe_cost_pipeline_with_yield_and_waste(): void
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

        $service = app(RecipeCostService::class);
        $raw = 100000.0;
        $this->assertSame(125000.0, $service->calculateYieldAdjustedCost($raw, 80));
        $this->assertSame(131250.0, $service->calculateWasteAdjustedCost(125000, 5));
        $this->assertSame(131250.0, $service->calculateMenuCost($menu['menuId'], (int) $outlet->id));
    }
}
