<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class OptimizationApiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_menu_optimization_api_endpoints(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 35000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 15000);

        $q = '?outletId='.$outlet->id;

        $this->getJson('/api/v1/menu-optimization/recommendations'.$q)->assertOk()
            ->assertJsonStructure(['data' => ['recommendations', 'pricing', 'bundles']]);
        $this->getJson('/api/v1/menu-optimization/recommendations/stars'.$q)->assertOk();
        $this->getJson('/api/v1/menu-optimization/pricing/opportunities'.$q)->assertOk();
        $this->getJson('/api/v1/menu-optimization/bundles/top'.$q)->assertOk();
        $this->getJson('/api/v1/menu-optimization/ingredients/opportunities'.$q)->assertOk();
        $this->getJson('/api/v1/menu-optimization/yield/opportunities'.$q)->assertOk();
        $this->postJson('/api/v1/menu-optimization/simulate-price'.$q, [
            'menuItemId' => $menu['menuId'],
            'newPrice' => 40000,
        ])->assertOk();
        $this->postJson('/api/v1/menu-optimization/snapshots/create'.$q)->assertCreated();
        $this->getJson('/api/v1/menu-optimization/snapshots'.$q)->assertOk();
    }
}
