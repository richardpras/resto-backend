<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\TestCase;

class ProductionStationMenuAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use ProductionStationTestFixture;

    private function actingAsPosUser(Outlet $outlet): User
    {
        return $this->createUserWithPermission('pos.use', $outlet);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seed(UserManagementPermissionsSeeder::class);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_menu_item_create_and_update_expose_production_station(): void
    {
        $outlet = $this->createProductionStationOutlet();
        $kitchen = ProductionStation::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'kitchen',
            'name' => 'Kitchen',
            'type' => 'kitchen',
            'display_order' => 10,
            'is_active' => true,
            'kds_enabled' => true,
            'print_enabled' => true,
        ]);
        $bar = ProductionStation::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'bar',
            'name' => 'Bar',
            'type' => 'bar',
            'display_order' => 20,
            'is_active' => true,
            'kds_enabled' => true,
            'print_enabled' => true,
        ]);

        $user = $this->actingAsPosUser($outlet);
        Passport::actingAs($user);

        $created = $this->postJson('/api/v1/menu-items', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'name' => 'Nasi Goreng',
            'category' => 'Food',
            'emoji' => '🍽️',
            'price' => 45000,
            'available' => true,
            'productionStationId' => $kitchen->id,
        ])->assertCreated()
            ->assertJsonPath('data.productionStation.id', (int) $kitchen->id)
            ->assertJsonPath('data.productionStation.code', 'kitchen')
            ->json('data');

        $menuItemId = (int) $created['id'];

        $this->putJson('/api/v1/menu-items/'.$menuItemId, [
            'productionStationId' => $bar->id,
        ])->assertOk()
            ->assertJsonPath('data.productionStation.id', (int) $bar->id)
            ->assertJsonPath('data.productionStation.code', 'bar');

        $this->putJson('/api/v1/menu-items/'.$menuItemId, [
            'productionStationId' => null,
        ])->assertOk()
            ->assertJsonPath('data.productionStation', null);

        $this->assertDatabaseHas('menu_items', [
            'id' => $menuItemId,
            'production_station_id' => null,
        ]);
    }

    public function test_menu_item_rejects_station_from_different_outlet(): void
    {
        $outletA = $this->createProductionStationOutlet();
        $outletB = $this->createProductionStationOutlet();
        $foreignStation = ProductionStation::query()->create([
            'outlet_id' => $outletB->id,
            'code' => 'kitchen',
            'name' => 'Kitchen B',
            'type' => 'kitchen',
            'display_order' => 10,
            'is_active' => true,
            'kds_enabled' => true,
            'print_enabled' => true,
        ]);

        $user = $this->actingAsPosUser($outletA);
        Passport::actingAs($user);

        $this->postJson('/api/v1/menu-items', [
            'tenantId' => 1,
            'outletId' => $outletA->id,
            'name' => 'Es Teh',
            'category' => 'Beverage',
            'emoji' => '🥤',
            'price' => 15000,
            'productionStationId' => $foreignStation->id,
        ])->assertUnprocessable();
    }

    public function test_existing_menu_items_without_station_remain_valid(): void
    {
        $outlet = $this->createProductionStationOutlet();
        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Legacy Item',
            'category' => 'Food',
            'emoji' => '🍽️',
            'price' => 10000,
            'available' => true,
        ]);

        $user = $this->actingAsPosUser($outlet);
        Passport::actingAs($user);

        $this->getJson('/api/v1/menu-items/'.$menuItem->id)
            ->assertOk()
            ->assertJsonPath('data.productionStation', null);
    }
}
