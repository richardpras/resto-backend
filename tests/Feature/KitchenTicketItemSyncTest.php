<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class KitchenTicketItemSyncTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_kitchen_ticket_items_sync_when_order_items_added_after_reservation_start_service(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Sync Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-SYNC',
            'status' => 'active',
            'active' => true,
        ])->id;

        PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $reservationId = (int) $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Sync Guest',
            'partySize' => 2,
            'reservationAt' => now()->addHour()->toISOString(),
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')->assertOk();

        $start = $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();
        $linkedOrderId = (int) $start->json('linkedOrderId');

        $this->assertSame(0, DB::table('kitchen_tickets')->where('order_id', $linkedOrderId)->count());

        $this->patchJson('/api/v1/orders/'.$linkedOrderId, [
            'items' => [
                ['id' => '701', 'name' => 'Grilled Fish', 'qty' => 2, 'price' => 45000],
                ['id' => '702', 'name' => 'Rice', 'qty' => 1, 'price' => 15000],
            ],
            'subtotal' => 105000,
            'tax' => 10500,
            'total' => 115500,
        ])->assertOk();

        $ticketId = (int) DB::table('kitchen_tickets')->where('order_id', $linkedOrderId)->value('id');
        $this->assertGreaterThan(0, $ticketId);
        $this->assertSame(2, DB::table('kitchen_ticket_items')->where('kitchen_ticket_id', $ticketId)->count());
        $this->assertDatabaseHas('kitchen_ticket_items', [
            'kitchen_ticket_id' => $ticketId,
            'item_name_snapshot' => 'Grilled Fish',
            'qty' => 2,
        ]);
        $this->assertDatabaseHas('kitchen_ticket_items', [
            'kitchen_ticket_id' => $ticketId,
            'item_name_snapshot' => 'Rice',
            'qty' => 1,
        ]);

        $this->assertSame(1, DB::table('kitchen_tickets')->where('order_id', $linkedOrderId)->count());

        $list = $this->getJson('/api/v1/kitchen/tickets?outletId='.$outlet->id.'&status=queued')->assertOk();
        $list->assertJsonPath('data.0.id', $ticketId)
            ->assertJsonPath('data.0.orderNumber', fn ($value) => is_string($value) && $value !== '')
            ->assertJsonPath('data.0.serviceMode', 'dine_in')
            ->assertJsonCount(2, 'data.0.items');
    }

    public function test_replacing_order_items_removes_stale_kitchen_ticket_lines_without_duplicates(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Replace Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $orderId = (int) $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'code' => 'KSYNC-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'serviceMode' => 'takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '1', 'name' => 'Old Item', 'qty' => 1, 'price' => 10000],
            ],
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'payments' => [],
        ])->assertCreated()->json('data.id');

        $ticketId = (int) DB::table('kitchen_tickets')->where('order_id', $orderId)->value('id');
        $this->assertSame(1, DB::table('kitchen_ticket_items')->where('kitchen_ticket_id', $ticketId)->count());

        $this->patchJson('/api/v1/orders/'.$orderId, [
            'items' => [
                ['id' => '2', 'name' => 'New Item A', 'qty' => 1, 'price' => 12000],
                ['id' => '3', 'name' => 'New Item B', 'qty' => 3, 'price' => 5000],
            ],
            'subtotal' => 27000,
            'tax' => 0,
            'total' => 27000,
        ])->assertOk();

        $this->assertSame(2, DB::table('kitchen_ticket_items')->where('kitchen_ticket_id', $ticketId)->count());
        $this->assertDatabaseMissing('kitchen_ticket_items', [
            'kitchen_ticket_id' => $ticketId,
            'item_name_snapshot' => 'Old Item',
        ]);
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name.' '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ksync-'.uniqid(),
        ]);
    }
}
