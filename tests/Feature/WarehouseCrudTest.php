<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class WarehouseCrudTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_create_list_and_deactivate_warehouse(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);

        $create = $this->postJson('/api/v1/warehouses', [
            'outletId' => $outlet->id,
            'code' => 'wh-test',
            'name' => 'Main Storage',
        ])->assertCreated();

        $warehouseId = (int) $create->json('data.id');

        $this->getJson('/api/v1/warehouses?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonFragment(['code' => 'WH-TEST', 'name' => 'Main Storage']);

        $this->patchJson("/api/v1/warehouses/{$warehouseId}", [
            'name' => 'Updated Storage',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Storage');

        $this->deleteJson("/api/v1/warehouses/{$warehouseId}")
            ->assertOk()
            ->assertJsonPath('data.isActive', false);
    }
}
