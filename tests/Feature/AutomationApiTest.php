<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class AutomationApiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_menu_automation_api_endpoints(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);
        $q = '?outletId='.$outlet->id;

        $this->getJson('/api/v1/menu-automation/alerts'.$q)->assertOk();
        $this->getJson('/api/v1/menu-automation/alerts/open'.$q)->assertOk();
        $this->getJson('/api/v1/menu-automation/rules'.$q)->assertOk();
        $this->getJson('/api/v1/menu-automation/dashboard-summary'.$q)->assertOk();
        $this->getJson('/api/v1/menu-automation/escalations'.$q)->assertOk();
        $this->postJson('/api/v1/menu-automation/snapshots/create'.$q)->assertCreated();
        $this->getJson('/api/v1/menu-automation/snapshots'.$q)->assertOk();
    }
}
