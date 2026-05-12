<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OrderPosEventsEndpointTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_list_events_returns_order_and_split_payload_rows_for_allowed_outlet(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Events Outlet A');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $orderId = $this->seedDirectOrder($outlet->id, 'EVT-ORDER-1', 'unpaid');

        PosEventLog::query()->create([
            'outlet_id' => $outlet->id,
            'actor_user_id' => $user->id,
            'event_type' => 'order.created',
            'entity_type' => 'order',
            'entity_id' => $orderId,
            'payload' => ['code' => 'EVT-ORDER-1'],
            'occurred_at' => now()->subMinute(),
        ]);

        PosEventLog::query()->create([
            'outlet_id' => $outlet->id,
            'actor_user_id' => null,
            'event_type' => 'split.updated',
            'entity_type' => 'order_split',
            'entity_id' => 77,
            'payload' => ['orderId' => $orderId, 'label' => 'A'],
            'occurred_at' => now(),
        ]);

        $otherOutlet = $this->createOutlet('Events Outlet B');
        PosEventLog::query()->create([
            'outlet_id' => $otherOutlet->id,
            'actor_user_id' => null,
            'event_type' => 'order.created',
            'entity_type' => 'order',
            'entity_id' => $orderId,
            'payload' => [],
            'occurred_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/orders/'.$orderId.'/events');
        $response->assertOk();
        $types = collect($response->json('data'))->pluck('eventType')->all();
        self::assertContains('order.created', $types);
        self::assertContains('split.updated', $types);
        self::assertCount(2, $response->json('data'));
    }

    public function test_list_events_for_order_outside_assigned_outlets_returns_not_found(): void
    {
        $this->seedUserManagementGatePermissions();
        $posUse = Permission::query()->where('code', 'pos.use')->firstOrFail();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_order_events_scoped__'],
            ['description' => 'Fixture: outlet-scoped POS only'],
        );
        $role->permissions()->sync([$posUse->id]);

        $user = User::factory()->create([
            'email' => 'order-events-scoped-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        $allowed = $this->createOutlet('Events Allowed');
        $forbidden = $this->createOutlet('Events Forbidden');
        $this->assignUserToOutlets($user, [$allowed->id]);
        Passport::actingAs($user);

        $forbiddenOrderId = $this->seedDirectOrder($forbidden->id, 'EVT-FORBIDDEN', 'unpaid');

        PosEventLog::query()->create([
            'outlet_id' => $forbidden->id,
            'actor_user_id' => null,
            'event_type' => 'order.created',
            'entity_type' => 'order',
            'entity_id' => $forbiddenOrderId,
            'payload' => [],
            'occurred_at' => now(),
        ]);

        $this->getJson('/api/v1/orders/'.$forbiddenOrderId.'/events')->assertNotFound();
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'evt-'.uniqid(),
        ]);
    }

    private function seedDirectOrder(int $outletId, string $code, string $paymentStatus): int
    {
        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'pending',
            'payment_status' => $paymentStatus,
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid_total' => 0,
            'balance_due' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'item_id' => '999',
            'name' => 'Seed item',
            'qty' => 1,
            'price' => 10000,
            'line_total' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $orderId;
    }
}
