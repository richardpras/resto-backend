<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\RecipeVersion;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class RecipeVersionBootstrapTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_bootstrap_activates_existing_draft_instead_of_creating_duplicate_version_one(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.2);

        RecipeVersion::query()->create([
            'menu_item_id' => $menu['menuId'],
            'version_number' => 1,
            'name' => 'Version 1',
            'status' => 'draft',
        ]);

        $service = app(RecipeVersionService::class);
        $active = $service->getActiveVersion($menu['menuId']);

        $this->assertSame(1, (int) $active->version_number);
        $this->assertSame('active', $active->status);
        $this->assertSame(1, RecipeVersion::query()->where('menu_item_id', $menu['menuId'])->count());
    }
}
