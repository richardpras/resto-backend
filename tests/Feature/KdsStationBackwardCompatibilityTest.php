<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\KdsStationTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class KdsStationBackwardCompatibilityTest extends TestCase
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

    public function test_legacy_no_station_ticket_serializes_safely(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();

        $orderId = (int) DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'KDS-LEGACY-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'service_mode' => 'takeaway',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'kitchen_status' => 'queued',
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid_total' => 0,
            'balance_due' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = $this->seedLegacyKitchenTicket($outlet, $orderId);

        $response = $this->getJson('/api/v1/kitchen/tickets?outletId='.$outlet->id);
        $response->assertOk();
        $response->assertJsonPath('data.0.id', $ticketId);
        $response->assertJsonPath('data.0.station', null);

        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', [
            'status' => 'in_progress',
        ])->assertOk()->assertJsonPath('data.station', null);
    }

    public function test_legacy_ticket_appears_in_all_view_with_station_filter_absent(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionDefaultStations($outlet);
        $nasi = $this->createMenuItem($outlet, 'Nasi Goreng', $stations['kitchen']);

        $legacyOrderId = (int) DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'KDS-LEGACY2-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'service_mode' => 'takeaway',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'kitchen_status' => 'queued',
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid_total' => 0,
            'balance_due' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $legacyTicketId = $this->seedLegacyKitchenTicket($outlet, $legacyOrderId);

        $this->createConfirmedOrderWithMenuItems($outlet, 'KDS-STATIONED-'.uniqid(), [
            ['menuItem' => $nasi],
        ]);

        $all = $this->getJson('/api/v1/kitchen/tickets?outletId='.$outlet->id);
        $all->assertOk();
        $all->assertJsonCount(2, 'data');

        $ids = collect($all->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($legacyTicketId, $ids);
    }

    /** @return array{0: \App\Models\User, 1: Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'KDS Legacy Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'kds-legacy-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}
