<?php

namespace Tests\Feature;

use App\Models\Modules\Production\Domain\ProductionStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\TestCase;

class ProductionStationCrudTest extends TestCase
{
    use RefreshDatabase;
    use ProductionStationTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedProductionStationPermissions();
        \Illuminate\Support\Facades\Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_list_create_update_and_deactivate_production_station(): void
    {
        $outlet = $this->createProductionStationOutlet();
        $this->actingAsProductionStationManager($outlet);

        $this->getJson('/api/v1/production-stations?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data', []);

        $created = $this->postJson('/api/v1/production-stations', [
            'outletId' => $outlet->id,
            'code' => 'cold_bar',
            'name' => 'Cold Bar',
            'type' => 'bar',
            'displayOrder' => 15,
            'kdsEnabled' => true,
            'printEnabled' => true,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'cold_bar')
            ->assertJsonPath('data.name', 'Cold Bar')
            ->json('data');

        $stationId = (int) $created['id'];

        $this->getJson('/api/v1/production-stations?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $stationId);

        $this->putJson('/api/v1/production-stations/'.$stationId, [
            'name' => 'Cold Bar Updated',
            'displayOrder' => 12,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Cold Bar Updated')
            ->assertJsonPath('data.displayOrder', 12);

        $this->patchJson('/api/v1/production-stations/'.$stationId.'/status', [
            'isActive' => false,
        ])->assertOk()
            ->assertJsonPath('data.isActive', false);

        $this->assertDatabaseHas('production_stations', [
            'id' => $stationId,
            'is_active' => false,
            'name' => 'Cold Bar Updated',
        ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $outlet = $this->createProductionStationOutlet();

        $this->getJson('/api/v1/production-stations?outletId='.$outlet->id)->assertUnauthorized();
    }

    public function test_user_without_settings_manage_cannot_create_station(): void
    {
        $outlet = $this->createProductionStationOutlet();
        $user = $this->createUserWithPermission('settings.view', $outlet);
        \Laravel\Passport\Passport::actingAs($user);

        $this->postJson('/api/v1/production-stations', [
            'outletId' => $outlet->id,
            'name' => 'Kitchen',
        ])->assertForbidden();
    }

    public function test_duplicate_code_per_outlet_is_rejected(): void
    {
        $outlet = $this->createProductionStationOutlet();
        $this->actingAsProductionStationManager($outlet);

        ProductionStation::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'kitchen',
            'name' => 'Kitchen',
            'type' => 'kitchen',
            'display_order' => 10,
            'is_active' => true,
            'kds_enabled' => true,
            'print_enabled' => true,
        ]);

        $this->postJson('/api/v1/production-stations', [
            'outletId' => $outlet->id,
            'code' => 'kitchen',
            'name' => 'Kitchen Duplicate',
        ])->assertUnprocessable();
    }
}
