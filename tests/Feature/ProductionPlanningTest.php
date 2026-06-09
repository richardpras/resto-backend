<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\ProductionPlanningService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ProductionPlanningTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_generate_production_plan_calculates_ingredient_requirements(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, stock: 20);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.25);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $plan = app(ProductionPlanningService::class)->generateProductionPlan($outlet->id, [
            ['menuItemId' => $menu['menuId'], 'quantity' => 50],
        ]);

        $this->assertCount(1, $plan['menuDemand']);
        $requirement = collect($plan['requirements'])->firstWhere('ingredientId', (string) $ingredient->id);
        $this->assertNotNull($requirement);
        $this->assertSame(12.5, (float) $requirement['requiredQuantity']);
    }
}
