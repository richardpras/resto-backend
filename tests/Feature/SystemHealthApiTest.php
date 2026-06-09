<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class SystemHealthApiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_system_health_endpoint_returns_expected_shape(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);

        $this->getJson('/api/v1/menu-dashboard/system-health?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'score',
                    'status',
                    'analytics',
                    'engineering',
                    'optimization',
                    'automation',
                    'forecasting',
                    'cache',
                    'issues',
                ],
            ]);
    }
}
