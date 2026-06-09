<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class RecipeVersionHistoryTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_all_versions_remain_in_history(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.2);

        $service = app(RecipeVersionService::class);
        $service->createVersion($menu['menuId'], [['ingredientId' => (int) $ingredient->id, 'quantity' => 0.2]], activate: true);
        $service->createVersion($menu['menuId'], [['ingredientId' => (int) $ingredient->id, 'quantity' => 0.25]], activate: true);
        $service->createVersion($menu['menuId'], [['ingredientId' => (int) $ingredient->id, 'quantity' => 0.3]], activate: true);

        $versions = $service->listVersions($menu['menuId']);

        $this->assertCount(3, $versions);
        $this->assertSame([3, 2, 1], $versions->pluck('version_number')->all());
        $this->assertSame('active', $versions->first()->status);
        $this->assertSame('archived', $versions->last()->status);
    }
}
