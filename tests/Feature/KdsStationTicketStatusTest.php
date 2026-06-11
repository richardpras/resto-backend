<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\KdsStationTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class KdsStationTicketStatusTest extends TestCase
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

    public function test_station_ticket_statuses_are_independent(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionDefaultStations($outlet);

        $nasi = $this->createMenuItem($outlet, 'Nasi Goreng', $stations['kitchen']);
        $esTeh = $this->createMenuItem($outlet, 'Es Teh', $stations['bar'], 'Beverage');
        $croissant = $this->createMenuItem($outlet, 'Croissant', $stations['bakery']);

        $orderId = $this->createConfirmedOrderWithMenuItems($outlet, 'KDS-STATUS-'.uniqid(), [
            ['menuItem' => $nasi],
            ['menuItem' => $esTeh],
            ['menuItem' => $croissant],
        ]);

        $kitchenTicketId = (int) DB::table('kitchen_tickets')->where('order_id', $orderId)->where('station_code', 'kitchen')->value('id');
        $barTicketId = (int) DB::table('kitchen_tickets')->where('order_id', $orderId)->where('station_code', 'bar')->value('id');
        $bakeryTicketId = (int) DB::table('kitchen_tickets')->where('order_id', $orderId)->where('station_code', 'bakery')->value('id');

        $this->patchJson('/api/v1/kitchen/tickets/'.$kitchenTicketId.'/status', ['status' => 'in_progress'])->assertOk();
        $this->patchJson('/api/v1/kitchen/tickets/'.$barTicketId.'/status', ['status' => 'in_progress'])->assertOk();
        $this->patchJson('/api/v1/kitchen/tickets/'.$barTicketId.'/status', ['status' => 'ready'])->assertOk();

        $this->assertDatabaseHas('kitchen_tickets', ['id' => $kitchenTicketId, 'status' => 'in_progress']);
        $this->assertDatabaseHas('kitchen_tickets', ['id' => $barTicketId, 'status' => 'ready']);
        $this->assertDatabaseHas('kitchen_tickets', ['id' => $bakeryTicketId, 'status' => 'queued']);
    }

    /** @return array{0: \App\Models\User, 1: Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'KDS Status Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'kds-status-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}
