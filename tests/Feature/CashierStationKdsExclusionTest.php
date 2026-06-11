<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CashierStationValidationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class CashierStationKdsExclusionTest extends TestCase
{
    use RefreshDatabase;
    use CashierStationValidationFixture;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_mixed_order_excludes_cashier_station_from_kds_tickets(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionCashierValidationStations($outlet);
        $items = $this->createCashierValidationMenuItems($outlet, $stations);

        $order = $this->createConfirmedCashierValidationOrder(
            $outlet,
            $items['nasi'],
            $items['esTeh'],
            $items['rokok'],
        );
        $orderId = $order['orderId'];

        $this->assertSame(2, DB::table('kitchen_tickets')->where('order_id', $orderId)->count());
        $this->assertDatabaseMissing('kitchen_tickets', [
            'order_id' => $orderId,
            'station_code' => 'cashier',
        ]);

        $kitchenTicketId = (int) DB::table('kitchen_tickets')
            ->where('order_id', $orderId)
            ->where('station_code', 'kitchen')
            ->value('id');
        $barTicketId = (int) DB::table('kitchen_tickets')
            ->where('order_id', $orderId)
            ->where('station_code', 'bar')
            ->value('id');

        $this->assertDatabaseHas('kitchen_ticket_items', [
            'kitchen_ticket_id' => $kitchenTicketId,
            'item_name_snapshot' => 'Nasi Goreng',
        ]);
        $this->assertDatabaseHas('kitchen_ticket_items', [
            'kitchen_ticket_id' => $barTicketId,
            'item_name_snapshot' => 'Es Teh',
        ]);
        $this->assertDatabaseMissing('kitchen_ticket_items', [
            'item_name_snapshot' => 'Rokok Marlboro',
        ]);
    }

    /** @return array{0: \App\Models\User, 1: Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Cashier KDS Validation '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'cashier-kds-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}
