<?php

namespace Tests\Feature;

use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PrinterStationRouteApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_printer_route_api_accepts_production_station_id(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'code' => 'ps-api-'.uniqid(),
            'name' => 'API Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        $station = ProductionStation::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'kitchen',
            'name' => 'Kitchen',
            'type' => 'kitchen',
            'display_order' => 1,
            'is_active' => true,
            'kds_enabled' => true,
            'print_enabled' => true,
        ]);
        $profile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'kitchen-api',
            'name' => 'Kitchen Printer',
            'station' => 'kitchen',
            'connection_type' => 'lan',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/print/routes', [
            'outletId' => $outlet->id,
            'printerProfileId' => $profile->id,
            'printType' => 'kitchen',
            'routeScope' => 'production_station',
            'productionStationId' => $station->id,
            'priority' => 10,
        ])->assertCreated()
            ->assertJsonPath('data.productionStationId', $station->id)
            ->assertJsonPath('data.productionStation.code', 'kitchen');
    }

    public function test_legacy_station_and_category_route_api_still_works(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'code' => 'ps-legacy-api-'.uniqid(),
            'name' => 'Legacy API Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);
        $profile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'bar-legacy',
            'name' => 'Bar Printer',
            'station' => 'bar',
            'connection_type' => 'lan',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/print/routes', [
            'outletId' => $outlet->id,
            'printerProfileId' => $profile->id,
            'printType' => 'kitchen',
            'routeScope' => 'category',
            'station' => 'bar',
            'category' => 'Beverage',
            'priority' => 20,
        ])->assertCreated()
            ->assertJsonPath('data.station', 'bar')
            ->assertJsonPath('data.category', 'Beverage');
    }
}
