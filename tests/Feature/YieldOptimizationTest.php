<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\RecipeCostService;
use App\Modules\Menu\Services\YieldOptimizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class YieldOptimizationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_detects_excessive_waste_opportunity(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 50000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);
        app(RecipeCostService::class)->updateWastePercent($menu['menuId'], 15.0);

        $result = app(YieldOptimizationService::class)->analyzeMenuItem($menu['menuId'], (int) $outlet->id);

        $this->assertNotNull($result);
        $this->assertContains('excessive_waste', $result['issues']);
        $this->assertGreaterThan(0, $result['projectedSavings']);
        $this->assertLessThan($result['currentCost'], $result['optimizedCost']);
    }
}
