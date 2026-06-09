<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuRecipeCostSetting;
use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class RecipeCostApiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_menu_costing_api_endpoints(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 100000);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 50000);
        MenuRecipeCostSetting::query()->create([
            'menu_item_id' => $menu['menuId'],
            'yield_percent' => 100,
            'waste_percent' => 0,
            'is_active' => true,
        ]);

        $q = '?outletId='.$outlet->id;

        $this->getJson('/api/v1/menu-costing/menu-items/'.$menu['menuId'].'/breakdown'.$q)
            ->assertOk()
            ->assertJsonPath('data.rawCost', 50000)
            ->assertJsonPath('data.finalTheoreticalCost', 50000);

        $this->getJson('/api/v1/menu-costing/menu-items/'.$menu['menuId'].'/food-cost'.$q)
            ->assertOk()
            ->assertJsonPath('data.theoreticalFoodCostPercent', 50);

        $this->getJson('/api/v1/menu-costing/menu-items/'.$menu['menuId'].'/history'.$q)
            ->assertOk()
            ->assertJsonStructure(['data' => ['currentCost', 'history']]);

        $this->postJson('/api/v1/menu-costing/menu-items/'.$menu['menuId'].'/recalculate'.$q)
            ->assertOk()
            ->assertJsonPath('data.finalTheoreticalCost', 50000);
    }
}
