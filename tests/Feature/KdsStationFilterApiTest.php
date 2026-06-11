<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\KdsStationTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class KdsStationFilterApiTest extends TestCase
{
    use RefreshDatabase;
    use KdsStationTestFixture;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_station_code_filter_returns_only_matching_tickets(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionDefaultStations($outlet);

        $nasi = $this->createMenuItem($outlet, 'Nasi Goreng', $stations['kitchen']);
        $esTeh = $this->createMenuItem($outlet, 'Es Teh', $stations['bar'], 'Beverage');

        $this->createConfirmedOrderWithMenuItems($outlet, 'KDS-FILTER-'.uniqid(), [
            ['menuItem' => $nasi],
            ['menuItem' => $esTeh],
        ]);

        $kitchenList = $this->getJson('/api/v1/kitchen/tickets?outletId='.$outlet->id.'&stationCode=kitchen');
        $kitchenList->assertOk();
        $kitchenList->assertJsonCount(1, 'data');
        $kitchenList->assertJsonPath('data.0.station.code', 'kitchen');
        $kitchenList->assertJsonPath('data.0.items.0.name', 'Nasi Goreng');
    }

    public function test_list_without_station_filter_returns_all_tickets(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionDefaultStations($outlet);

        $nasi = $this->createMenuItem($outlet, 'Nasi Goreng', $stations['kitchen']);
        $esTeh = $this->createMenuItem($outlet, 'Es Teh', $stations['bar'], 'Beverage');

        $this->createConfirmedOrderWithMenuItems($outlet, 'KDS-ALL-'.uniqid(), [
            ['menuItem' => $nasi],
            ['menuItem' => $esTeh],
        ]);

        $all = $this->getJson('/api/v1/kitchen/tickets?outletId='.$outlet->id);
        $all->assertOk();
        $all->assertJsonCount(2, 'data');

        $codes = collect($all->json('data'))->pluck('station.code')->filter()->values()->all();
        $this->assertEqualsCanonicalizing(['kitchen', 'bar'], $codes);
    }

    public function test_station_id_filter_returns_only_matching_tickets(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionDefaultStations($outlet);

        $croissant = $this->createMenuItem($outlet, 'Croissant', $stations['bakery']);

        $this->createConfirmedOrderWithMenuItems($outlet, 'KDS-STID-'.uniqid(), [
            ['menuItem' => $croissant],
        ]);

        $bakeryId = (int) $stations['bakery']->id;
        $response = $this->getJson('/api/v1/kitchen/tickets?outletId='.$outlet->id.'&stationId='.$bakeryId);
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.station.id', $bakeryId);
        $response->assertJsonPath('data.0.station.code', 'bakery');
    }

    /** @return array{0: \App\Models\User, 1: Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'KDS Filter Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'kds-filter-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}
