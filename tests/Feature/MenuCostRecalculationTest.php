<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class MenuCostRecalculationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_recalculate_uses_latest_average_without_touching_snapshots(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 5, 10000);
        $first = app(RecipeCostService::class)->recalculateMenuCost($menu['menuId'], (int) $outlet->id);
        $this->assertSame(10000.0, $first['finalTheoreticalCost']);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 5, 20000);
        $second = app(RecipeCostService::class)->recalculateMenuCost($menu['menuId'], (int) $outlet->id);
        $this->assertSame(15000.0, $second['finalTheoreticalCost']);
    }
}
