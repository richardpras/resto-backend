<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuSimulationService;
use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class MenuSimulationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_price_recipe_and_yield_simulations(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 25000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);

        $sim = app(MenuSimulationService::class);
        $oid = (int) $outlet->id;
        $mid = $menu['menuId'];
        $iid = (int) $ingredient->id;

        $price = $sim->simulatePrice($mid, $oid, 27000);
        $this->assertSame(27000.0, $price['newPrice']);
        $this->assertGreaterThan($price['currentMarginPercent'], $price['newMarginPercent']);

        $recipe = $sim->simulateRecipe($mid, $oid, [
            ['inventoryItemId' => $iid, 'quantity' => 0.5],
        ]);
        $this->assertGreaterThan(0, $recipe['costReduction']);
        $this->assertGreaterThan($recipe['currentMarginPercent'], $recipe['newMarginPercent']);

        app(RecipeCostService::class)->updateYieldPercent($mid, 85.0);
        $yield = $sim->simulateYield($mid, $oid, 95.0);
        $this->assertGreaterThan(0, $yield['costReduction']);
    }
}
