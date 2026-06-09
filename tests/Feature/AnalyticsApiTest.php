<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_menu_analytics_api_endpoints(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 100000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 40000);

        $q = '?outletId='.$outlet->id;

        $this->getJson('/api/v1/menu-analytics/executive'.$q)->assertOk()
            ->assertJsonStructure(['data' => ['averageFoodCostPercent', 'averageMarginPercent', 'inventoryValue']]);
        $this->getJson('/api/v1/menu-analytics/food-cost'.$q)->assertOk();
        $this->getJson('/api/v1/menu-analytics/profitability'.$q)->assertOk();
        $this->getJson('/api/v1/menu-analytics/production'.$q)->assertOk();
        $this->getJson('/api/v1/menu-analytics/inventory'.$q)->assertOk();
        $this->postJson('/api/v1/menu-analytics/snapshots/create'.$q)->assertCreated();
    }
}
