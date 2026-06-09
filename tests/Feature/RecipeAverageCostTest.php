<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\RecipeCostService;
use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class RecipeAverageCostTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_menu_cost_uses_outlet_average_not_master_price(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, price: 8000);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 2);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10500);

        $cost = app(RecipeCostService::class)->calculateMenuCost($menu['menuId'], (int) $outlet->id);

        $this->assertSame(21000.0, $cost);
        $this->assertNotSame(16000.0, $cost);
    }
}
