<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

/**
 * Phase 2 — order lifecycle, table session binding, payment-status
 * separation, and outlet-scoped order queries.
 */
class Phase2OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_non_owner_cannot_list_orders_outside_assigned_outlet(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowed = $this->createOutlet('Allowed Outlet');
        $forbidden = $this->createOutlet('Forbidden Outlet');
        $this->assignUserToOutlets($user, [$allowed->id]);

        $allowedOrderId = $this->seedDirectOrder($allowed->id, 'PHASE2-A1', 'unpaid');
        $forbiddenOrderId = $this->seedDirectOrder($forbidden->id, 'PHASE2-F1', 'unpaid');

        $allowedList = $this->getJson('/api/v1/orders?outletId='.$allowed->id);
        $allowedList->assertOk();
        $codes = collect($allowedList->json('data'))->pluck('code')->all();
        self::assertContains('PHASE2-A1', $codes);
        self::assertNotContains('PHASE2-F1', $codes);

        $forbiddenList = $this->getJson('/api/v1/orders?outletId='.$forbidden->id);
        $forbiddenList->assertUnprocessable();

        $this->getJson('/api/v1/orders/'.$forbiddenOrderId)->assertNotFound();

        $this->postJson('/api/v1/orders/'.$forbiddenOrderId.'/payments', [
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ])->assertNotFound();

        $this->patchJson('/api/v1/orders/'.$allowedOrderId, [
            'items' => [[
                'id' => '999',
                'name' => 'Updated Item',
                'qty' => 1,
                'price' => 5000,
            ]],
            'subtotal' => 5000,
            'tax' => 500,
            'total' => 5500,
        ])->assertSuccessful();
    }

    public function test_unpaid_order_is_editable(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();

        $created = $this->postJson('/api/v1/orders', $this->orderPayload([
            'outletId' => $outlet->id,
            'code' => 'PHASE2-EDIT-1',
            'serviceMode' => 'takeaway',
        ]));
        $created->assertCreated();
        $orderId = (int) $created->json('data.id');

        $update = $this->patchJson('/api/v1/orders/'.$orderId, [
            'items' => [[
                'id' => '202',
                'name' => 'Updated Item',
                'qty' => 2,
                'price' => 7500,
            ]],
            'subtotal' => 15000,
            'tax' => 1500,
            'total' => 16500,
        ]);
        $update->assertOk();
        self::assertEquals(15000.0, (float) $update->json('data.subtotal'));
        self::assertEquals(16500.0, (float) $update->json('data.total'));
        $update->assertJsonPath('data.items.0.name', 'Updated Item');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'subtotal' => 15000,
            'total' => 16500,
        ]);
    }

    public function test_paid_order_is_not_editable(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();

        $created = $this->postJson('/api/v1/orders', $this->orderPayload([
            'outletId' => $outlet->id,
            'code' => 'PHASE2-PAID-1',
            'serviceMode' => 'takeaway',
        ]));
        $created->assertCreated();
        $orderId = (int) $created->json('data.id');

        $pay = $this->postJson('/api/v1/orders/'.$orderId.'/payments', [
            'payments' => [['method' => 'cash', 'amount' => 11000]],
        ]);
        $pay->assertOk();
        $pay->assertJsonPath('data.paymentStatus', 'paid');

        $update = $this->patchJson('/api/v1/orders/'.$orderId, [
            'items' => [[
                'id' => '202',
                'name' => 'Should Not Save',
                'qty' => 1,
                'price' => 1,
            ]],
            'subtotal' => 1,
            'tax' => 0,
            'total' => 1,
        ]);
        $update->assertUnprocessable();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'subtotal' => 10000,
            'total' => 11000,
        ]);
    }

    public function test_takeaway_order_without_table_works(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();

        $resp = $this->postJson('/api/v1/orders', $this->orderPayload([
            'outletId' => $outlet->id,
            'code' => 'PHASE2-TAKE-1',
            'serviceMode' => 'takeaway',
        ]));
        $resp->assertCreated();
        $resp->assertJsonPath('data.serviceMode', 'takeaway');
        $resp->assertJsonPath('data.tableId', null);
        $resp->assertJsonPath('data.paymentStatus', 'unpaid');
    }

    public function test_dine_in_order_requires_table(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $this->openPosSessionFor($outlet->id);

        $resp = $this->postJson('/api/v1/orders', $this->orderPayload([
            'outletId' => $outlet->id,
            'code' => 'PHASE2-DINE-NO-TABLE',
            'serviceMode' => 'dine_in',
        ]));
        $resp->assertUnprocessable();
    }

    public function test_dine_in_order_requires_open_pos_session(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $table = $this->createTable($outlet->id, 'D1');

        $missing = $this->postJson('/api/v1/orders', $this->orderPayload([
            'outletId' => $outlet->id,
            'code' => 'PHASE2-DINE-NO-SESSION',
            'serviceMode' => 'dine_in',
            'tableId' => $table->id,
        ]));
        $missing->assertUnprocessable();

        $session = $this->openPosSessionFor($outlet->id);

        $ok = $this->postJson('/api/v1/orders', $this->orderPayload([
            'outletId' => $outlet->id,
            'code' => 'PHASE2-DINE-OK',
            'serviceMode' => 'dine_in',
            'tableId' => $table->id,
        ]));
        $ok->assertCreated();
        $ok->assertJsonPath('data.serviceMode', 'dine_in');
        $ok->assertJsonPath('data.tableId', (int) $table->id);
        $ok->assertJsonPath('data.posSessionId', (int) $session->id);
        $ok->assertJsonPath('data.paymentStatus', 'unpaid');
    }

    public function test_kitchen_status_independent_from_payment_status(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();

        $created = $this->postJson('/api/v1/orders', $this->orderPayload([
            'outletId' => $outlet->id,
            'code' => 'PHASE2-KITCHEN',
            'serviceMode' => 'takeaway',
        ]));
        $created->assertCreated();
        $created->assertJsonPath('data.kitchenStatus', 'queued');
        $created->assertJsonPath('data.paymentStatus', 'unpaid');

        $orderId = (int) $created->json('data.id');
        $this->postJson('/api/v1/orders/'.$orderId.'/payments', [
            'payments' => [['method' => 'cash', 'amount' => 11000]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid')
            ->assertJsonPath('data.kitchenStatus', 'queued');
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'tenantId' => 1,
            'code' => 'PHASE2-DEFAULT',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 1, 'price' => 10000],
            ],
            'subtotal' => 10000,
            'tax' => 1000,
            'total' => 11000,
            'payments' => [],
        ], $overrides);
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Modules\Settings\Domain\Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('P2 Outlet');
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
            'code' => 'p2-'.uniqid(),
        ]);
    }

    private function createTable(int $outletId, string $name): RestaurantTable
    {
        return RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => $name,
            'capacity' => 4,
            'status' => 'active',
        ]);
    }

    private function openPosSessionFor(int $outletId): PosSession
    {
        return PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => auth()->id(),
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
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
