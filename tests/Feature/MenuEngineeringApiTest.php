<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class MenuEngineeringApiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_menu_engineering_api_endpoints(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 80000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 20000);

        $q = '?outletId='.$outlet->id;

        $this->getJson('/api/v1/menu-engineering/matrix'.$q)->assertOk()
            ->assertJsonStructure(['data' => ['items', 'benchmarks', 'summary']]);
        $this->getJson('/api/v1/menu-engineering/matrix/stars'.$q)->assertOk();
        $this->getJson('/api/v1/menu-engineering/matrix/trends'.$q.'&fromDate='.now()->subMonth()->toDateString().'&toDate='.now()->toDateString())->assertOk();
        $this->getJson('/api/v1/menu-engineering/matrix/top-performers'.$q)->assertOk();
        $this->postJson('/api/v1/menu-engineering/matrix/snapshots/create'.$q)->assertCreated();
    }
}
