<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\RecipeVersion;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class RecipeVersionCreationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_create_version_increments_version_number(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.2);

        $service = app(RecipeVersionService::class);
        $v1 = $service->createVersion($menu['menuId'], [
            ['ingredientId' => (int) $ingredient->id, 'quantity' => 0.2, 'unit' => 'kg'],
        ], activate: true);

        $v2 = $service->createVersion($menu['menuId'], [
            ['ingredientId' => (int) $ingredient->id, 'quantity' => 0.25, 'unit' => 'kg'],
        ], activate: false);

        $this->assertSame(1, (int) $v1->version_number);
        $this->assertSame(2, (int) $v2->version_number);
        $this->assertSame('active', $v1->fresh()->status);
        $this->assertSame('draft', $v2->status);
        $this->assertSame(2, RecipeVersion::query()->where('menu_item_id', $menu['menuId'])->count());
    }
}
