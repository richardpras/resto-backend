<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuRecipe;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class RecipeVersionActivationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_activate_version_archives_previous_and_syncs_menu_recipes(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.2);

        $service = app(RecipeVersionService::class);
        $v1 = $service->createVersion($menu['menuId'], [
            ['ingredientId' => (int) $ingredient->id, 'quantity' => 0.2],
        ], activate: true);

        $v2 = $service->createVersion($menu['menuId'], [
            ['ingredientId' => (int) $ingredient->id, 'quantity' => 0.3],
        ], activate: false);

        $service->activateVersion($menu['menuId'], (int) $v2->id);

        $this->assertSame('archived', $v1->fresh()->status);
        $this->assertSame('active', $v2->fresh()->status);
        $this->assertSame(0.3, (float) MenuRecipe::query()
            ->where('menu_item_id', $menu['menuId'])
            ->value('quantity'));
    }
}
