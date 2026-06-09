<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ProductionApiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_menu_production_api_endpoints(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, stock: 100);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.25);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $q = '?outletId='.$outlet->id;

        $this->getJson('/api/v1/menu-production/menu-items/'.$menu['menuId'].'/versions')
            ->assertOk()
            ->assertJsonStructure(['data' => [['versionNumber', 'status', 'items']]]);

        $this->getJson('/api/v1/menu-production/production-plan'.$q.'&menuItemId='.$menu['menuId'].'&quantity=50')
            ->assertOk()
            ->assertJsonStructure(['data' => ['menuDemand', 'requirements']]);

        $this->getJson('/api/v1/menu-production/ingredient-demand'.$q.'&menuItemId='.$menu['menuId'].'&quantity=50')
            ->assertOk()
            ->assertJsonIsArray('data');

        $this->getJson('/api/v1/menu-production/shortages'.$q.'&menuItemId='.$menu['menuId'].'&quantity=50')
            ->assertOk()
            ->assertJsonStructure(['data' => ['shortages']]);

        $this->getJson('/api/v1/menu-production/prep-forecast'.$q.'&period=daily&fromDate='.now()->toDateString())
            ->assertOk()
            ->assertJsonStructure(['data' => ['prepRequirements']]);
    }
}
