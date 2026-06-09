<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\ProductionPlanningService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class IngredientDemandTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_generate_ingredient_demand_per_menu_item(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.2);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $demand = app(ProductionPlanningService::class)->generateIngredientDemand($outlet->id, [
            ['menuItemId' => $menu['menuId'], 'quantity' => 100],
        ]);

        $this->assertCount(1, $demand);
        $this->assertSame(20.0, (float) $demand[0]['requiredQuantity']);
    }
}
