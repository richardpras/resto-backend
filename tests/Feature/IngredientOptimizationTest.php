<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\IngredientOptimizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class IngredientOptimizationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_identifies_ingredient_cost_reduction_opportunity(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 50000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 18000);

        $result = app(IngredientOptimizationService::class)->analyzeMenuItem($menu['menuId'], (int) $outlet->id);

        $this->assertNotNull($result);
        $this->assertSame(18000.0, $result['currentCost']);
        $this->assertLessThan($result['currentCost'], $result['optimizedCost']);
        $this->assertGreaterThan(0, $result['marginIncreasePercent']);
    }
}
