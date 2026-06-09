<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ProfitabilityCalculationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_calculate_margin_from_selling_price_and_cost(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 100000);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 40000);

        $result = app(MenuProfitabilityService::class)->calculateProfitability($menu['menuId'], (int) $outlet->id);

        $this->assertSame(60000.0, $result['margin']);
        $this->assertSame(100000.0, $result['sellingPrice']);
        $this->assertSame(40000.0, $result['cost']);
    }
}
