<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\KdsStationTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class KdsStationRoutingTest extends TestCase
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

    public function test_mixed_station_order_creates_multiple_kds_tickets(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionDefaultStations($outlet);

        $nasi = $this->createMenuItem($outlet, 'Nasi Goreng', $stations['kitchen']);
        $esTeh = $this->createMenuItem($outlet, 'Es Teh', $stations['bar'], 'Beverage');
        $croissant = $this->createMenuItem($outlet, 'Croissant', $stations['bakery']);
        $rokok = $this->createMenuItem($outlet, 'Rokok Marlboro', $stations['cashier']);

        $orderId = $this->createConfirmedOrderWithMenuItems($outlet, 'KDS-MIX-'.uniqid(), [
            ['menuItem' => $nasi],
            ['menuItem' => $esTeh],
            ['menuItem' => $croissant],
            ['menuItem' => $rokok],
        ]);

        $this->assertSame(3, DB::table('kitchen_tickets')->where('order_id', $orderId)->count());
        $this->assertDatabaseMissing('kitchen_tickets', [
            'order_id' => $orderId,
            'station_code' => 'cashier',
        ]);
    }

    public function test_station_ticket_only_contains_matching_station_items(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionDefaultStations($outlet);

        $nasi = $this->createMenuItem($outlet, 'Nasi Goreng', $stations['kitchen']);
        $esTeh = $this->createMenuItem($outlet, 'Es Teh', $stations['bar'], 'Beverage');

        $orderId = $this->createConfirmedOrderWithMenuItems($outlet, 'KDS-ITEMS-'.uniqid(), [
            ['menuItem' => $nasi],
            ['menuItem' => $esTeh],
        ]);

        $kitchenTicketId = (int) DB::table('kitchen_tickets')
            ->where('order_id', $orderId)
            ->where('station_code', 'kitchen')
            ->value('id');
        $barTicketId = (int) DB::table('kitchen_tickets')
            ->where('order_id', $orderId)
            ->where('station_code', 'bar')
            ->value('id');

        $this->assertSame(1, DB::table('kitchen_ticket_items')->where('kitchen_ticket_id', $kitchenTicketId)->count());
        $this->assertDatabaseHas('kitchen_ticket_items', [
            'kitchen_ticket_id' => $kitchenTicketId,
            'item_name_snapshot' => 'Nasi Goreng',
            'station_code' => 'kitchen',
        ]);
        $this->assertDatabaseHas('kitchen_ticket_items', [
            'kitchen_ticket_id' => $barTicketId,
            'item_name_snapshot' => 'Es Teh',
            'station_code' => 'bar',
        ]);
    }

    public function test_cashier_station_with_kds_disabled_does_not_create_kds_ticket(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionDefaultStations($outlet);
        $rokok = $this->createMenuItem($outlet, 'Rokok Marlboro', $stations['cashier']);

        $orderId = $this->createConfirmedOrderWithMenuItems($outlet, 'KDS-CASHIER-'.uniqid(), [
            ['menuItem' => $rokok],
        ]);

        $this->assertSame(0, DB::table('kitchen_tickets')->where('order_id', $orderId)->count());
    }

    public function test_re_running_create_from_order_does_not_duplicate_tickets(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionDefaultStations($outlet);

        $nasi = $this->createMenuItem($outlet, 'Nasi Goreng', $stations['kitchen']);
        $esTeh = $this->createMenuItem($outlet, 'Es Teh', $stations['bar'], 'Beverage');

        $orderId = $this->createConfirmedOrderWithMenuItems($outlet, 'KDS-IDEM-'.uniqid(), [
            ['menuItem' => $nasi],
            ['menuItem' => $esTeh],
        ]);

        $this->resyncKitchenTickets($orderId);
        $this->resyncKitchenTickets($orderId);

        $this->assertSame(2, DB::table('kitchen_tickets')->where('order_id', $orderId)->count());
        $this->assertSame(2, DB::table('kitchen_ticket_items')
            ->whereIn('kitchen_ticket_id', DB::table('kitchen_tickets')->where('order_id', $orderId)->pluck('id'))
            ->count());
    }

    /** @return array{0: \App\Models\User, 1: Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'KDS Routing Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'kds-route-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}
