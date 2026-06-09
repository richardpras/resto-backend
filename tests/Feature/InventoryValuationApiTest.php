<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryValuationApiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_list_and_show_valuation_endpoints(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 5, 12000);

        $this->getJson('/api/v1/inventory/valuations?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.averageCost', 12000)
            ->assertJsonPath('data.0.stockQuantity', 5);

        $this->getJson('/api/v1/inventory/valuations/'.$ingredient->id.'?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.averageCost', 12000);
    }
}
