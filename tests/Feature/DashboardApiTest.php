<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_menu_dashboard_api_endpoints(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);

        $q = '?outletId='.$outlet->id;

        $this->getJson('/api/v1/menu-dashboard/summary'.$q)->assertOk()
            ->assertJsonStructure(['data' => ['kpis', 'engineering', 'health']]);
        $this->getJson('/api/v1/menu-dashboard/kpis'.$q)->assertOk();
        $this->getJson('/api/v1/menu-dashboard/engineering'.$q)->assertOk();
        $this->getJson('/api/v1/menu-dashboard/optimization'.$q)->assertOk();
        $this->getJson('/api/v1/menu-dashboard/automation'.$q)->assertOk();
        $this->getJson('/api/v1/menu-dashboard/forecasting'.$q)->assertOk();
        $this->getJson('/api/v1/menu-dashboard/inventory'.$q)->assertOk();
        $this->getJson('/api/v1/menu-dashboard/health'.$q)->assertOk();
        $this->getJson('/api/v1/menu-dashboard/system-health'.$q)->assertOk()
            ->assertJsonStructure(['data' => ['score', 'status', 'issues']]);
        $this->postJson('/api/v1/menu-dashboard/snapshots/create'.$q)->assertCreated();
        $this->getJson('/api/v1/menu-dashboard/snapshots'.$q)->assertOk();
    }
}
