<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class RecipeComparisonTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_compare_versions_returns_quantity_deltas(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.2);

        $service = app(RecipeVersionService::class);
        $v1 = $service->createVersion($menu['menuId'], [['ingredientId' => (int) $ingredient->id, 'quantity' => 0.2]], activate: true);
        $v2 = $service->createVersion($menu['menuId'], [['ingredientId' => (int) $ingredient->id, 'quantity' => 0.3]], activate: true);

        $comparison = $service->compareVersions($menu['menuId'], (int) $v1->id, (int) $v2->id);

        $this->assertCount(1, $comparison['changes']);
        $this->assertSame(0.1, $comparison['changes'][0]['quantityDelta']);
    }
}
