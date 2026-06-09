<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuPriceSimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class PriceSimulationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_price_simulation_calculates_proposed_margin(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 100000);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 40000);

        $result = app(MenuPriceSimulationService::class)->simulate(
            $menu['menuId'],
            (int) $outlet->id,
            [120000, 80000],
        );

        $this->assertSame(100000.0, $result['currentPrice']);
        $this->assertSame(40000.0, $result['currentCost']);
        $this->assertSame(60000.0, $result['currentMargin']);
        $this->assertSame(80000.0, $result['proposedMargin']);
        $this->assertSame(20000.0, $result['profitabilityChange']);
        $this->assertCount(2, $result['simulations']);
        $this->assertSame(40000.0, $result['simulations'][1]['proposedMargin']);
    }
}
