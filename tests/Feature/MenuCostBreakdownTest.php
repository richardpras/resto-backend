<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuRecipeCostSetting;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class MenuCostBreakdownTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_breakdown_includes_ingredient_lines_and_cost_stages(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 2, menuPrice: 100000);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10500);
        MenuRecipeCostSetting::query()->create([
            'menu_item_id' => $menu['menuId'],
            'yield_percent' => 80,
            'waste_percent' => 5,
            'is_active' => true,
        ]);

        $breakdown = app(RecipeCostService::class)->calculateMenuCostBreakdown($menu['menuId'], (int) $outlet->id);

        $this->assertSame(21000.0, $breakdown['rawCost']);
        $this->assertSame(26250.0, $breakdown['yieldAdjustedCost']);
        $this->assertSame(27562.5, $breakdown['wasteAdjustedCost']);
        $this->assertSame(27562.5, $breakdown['finalTheoreticalCost']);
        $this->assertCount(1, $breakdown['ingredients']);
        $this->assertSame(10500.0, $breakdown['ingredients'][0]['averageCost']);
    }
}
