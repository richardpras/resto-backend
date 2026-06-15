<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase4KdsLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_confirmed_order_generates_kitchen_ticket(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, 'P4-KDS-TICKET-1');

        $this->assertDatabaseHas('kitchen_tickets', [
            'order_id' => $orderId,
            'outlet_id' => $outlet->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('kitchen_ticket_items', 2);
    }

    public function test_kds_list_is_outlet_scoped_and_filterable(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowed = $this->createOutlet('P4 Allowed Outlet');
        $forbidden = $this->createOutlet('P4 Forbidden Outlet');
        $this->assignUserToOutlets($user, [$allowed->id]);

        $allowedOrderId = $this->createConfirmedOrder($allowed->id, 'P4-KDS-ALLOWED');
        $forbiddenOrderId = $this->seedConfirmedOrderWithTicket($forbidden->id, 'P4-KDS-FORBIDDEN');

        $allowedTicketId = (int) DB::table('kitchen_tickets')->where('order_id', $allowedOrderId)->value('id');
        DB::table('kitchen_tickets')->where('id', $allowedTicketId)->update([
            'status' => 'ready',
            'ready_at' => now(),
        ]);

        $list = $this->getJson('/api/v1/kitchen/tickets?outletId='.$allowed->id.'&status=ready&perPage=10');
        $list->assertOk();
        $list->assertJsonPath('meta.perPage', 10);
        $list->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.orderId', $allowedOrderId);
        $list->assertJsonPath('data.0.status', 'ready');

        $forbiddenList = $this->getJson('/api/v1/kitchen/tickets?outletId='.$forbidden->id);
        $forbiddenList->assertUnprocessable();
    }

    public function test_kds_lifecycle_validates_transitions(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, 'P4-KDS-LIFECYCLE');
        $ticketId = (int) DB::table('kitchen_tickets')->where('order_id', $orderId)->value('id');

        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', [
            'status' => 'in_progress',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', [
            'status' => 'served',
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', [
            'status' => 'ready',
        ])->assertOk()->assertJsonPath('data.status', 'ready');

        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', [
            'status' => 'served',
        ])->assertOk()->assertJsonPath('data.status', 'served');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'kitchen_status' => 'served',
        ]);
    }

    public function test_in_progress_ticket_normalizes_order_kitchen_status_to_cooking(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, 'P4-KDS-COOKING');
        $ticketId = (int) DB::table('kitchen_tickets')->where('order_id', $orderId)->value('id');

        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', [
            'status' => 'in_progress',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'kitchen_status' => 'cooking',
        ]);
    }

    public function test_non_owner_cannot_mutate_other_outlet_ticket(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowed = $this->createOutlet('P4 Allowed');
        $forbidden = $this->createOutlet('P4 Forbidden');
        $this->assignUserToOutlets($user, [$allowed->id]);

        $forbiddenOrderId = $this->seedConfirmedOrderWithTicket($forbidden->id, 'P4-KDS-FORBIDDEN-MUT');
        $forbiddenTicketId = (int) DB::table('kitchen_tickets')->where('order_id', $forbiddenOrderId)->value('id');

        $this->patchJson('/api/v1/kitchen/tickets/'.$forbiddenTicketId.'/status', [
            'status' => 'in_progress',
        ])->assertNotFound();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Modules\Settings\Domain\Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('P4 Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p4-'.uniqid(),
        ]);
    }

    private function createConfirmedOrder(int $outletId, string $code): int
    {
        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'serviceMode' => 'takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '301', 'name' => 'Item A', 'qty' => 1, 'price' => 10000, 'notes' => 'No chili'],
                ['id' => '302', 'name' => 'Item B', 'qty' => 2, 'price' => 5000, 'notes' => 'Less salt'],
            ],
            'subtotal' => 20000,
            'tax' => 1000,
            'total' => 21000,
            'payments' => [],
        ]);
        $create->assertCreated();

        return (int) $create->json('data.id');
    }

    private function seedConfirmedOrderWithTicket(int $outletId, string $code): int
    {
        $orderId = (int) DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'service_mode' => 'takeaway',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'kitchen_status' => 'queued',
            'subtotal' => 20000,
            'tax' => 1000,
            'total' => 21000,
            'paid_total' => 0,
            'balance_due' => 21000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'item_id' => '999',
            'name' => 'Seeded Item',
            'qty' => 1,
            'price' => 21000,
            'line_total' => 21000,
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = (int) DB::table('kitchen_tickets')->insertGetId([
            'outlet_id' => $outletId,
            'order_id' => $orderId,
            'ticket_no' => 'KDS-'.$outletId.'-'.$orderId,
            'status' => 'queued',
            'queued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('kitchen_ticket_items')->insert([
            'kitchen_ticket_id' => $ticketId,
            'order_item_id' => $itemId,
            'item_name_snapshot' => 'Seeded Item',
            'qty' => 1,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }
}
