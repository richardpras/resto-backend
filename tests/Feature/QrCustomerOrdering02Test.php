<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use App\Modules\Orders\Events\QrOrderCashierCalled;
use App\Modules\Orders\Events\QrOrderRequestSubmitted;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrCustomerOrdering02Test extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_customer_submit_does_not_create_kitchen_ticket(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $requestId = $this->createQrRequest($outlet->id, $table->id, $menuItem->id);

        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'status' => 'pending_cashier_confirmation',
        ]);
        $this->assertDatabaseCount('kitchen_tickets', 0);
    }

    public function test_submit_broadcasts_qr_order_request_submitted(): void
    {
        Event::fake([QrOrderRequestSubmitted::class]);
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $this->createQrRequest($outlet->id, $table->id, $menuItem->id);

        Event::assertDispatched(QrOrderRequestSubmitted::class);
    }

    public function test_call_cashier_updates_request_and_broadcasts(): void
    {
        Event::fake([QrOrderCashierCalled::class]);
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $requestId = $this->createQrRequest($outlet->id, $table->id, $menuItem->id);

        $response = $this->postJson("/api/v1/qr-orders/{$requestId}/call-cashier", [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.cashierCallCount', 1);

        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'cashier_call_count' => 1,
        ]);

        Event::assertDispatched(QrOrderCashierCalled::class);
    }

    public function test_call_cashier_multiple_times_increments_counter_without_affecting_core_flows(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $this->seedCashierSession($outlet->id);
        $requestId = $this->createQrRequest($outlet->id, $table->id, $menuItem->id);

        $this->postJson("/api/v1/qr-orders/{$requestId}/call-cashier", [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
        ])->assertOk();
        $this->postJson("/api/v1/qr-orders/{$requestId}/call-cashier", [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
        ])->assertOk()->assertJsonPath('data.cashierCallCount', 2);

        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'cashier_call_count' => 2,
            'status' => 'pending_cashier_confirmation',
        ]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('kitchen_tickets', 0);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)
            ->assertOk()
            ->assertJsonPath('data.orderCount', 0);
    }

    public function test_pending_queue_prioritizes_called_requests_then_recency_then_created_at(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $this->seedCashierSession($outlet->id);
        $tableB = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $tableC = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $tableD = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);

        $idA = $this->createQrRequest($outlet->id, $table->id, $menuItem->id);
        $idB = $this->createQrRequest($outlet->id, (int) $tableB->id, $menuItem->id);
        $idC = $this->createQrRequest($outlet->id, (int) $tableC->id, $menuItem->id);
        $idD = $this->createQrRequest($outlet->id, (int) $tableD->id, $menuItem->id);

        $baseCreated = now()->subMinutes(15);
        DB::table('qr_order_requests')->where('id', $idA)->update([
            'cashier_call_count' => 3,
            'cashier_called_at' => now()->subMinutes(2),
            'created_at' => $baseCreated->copy()->addSeconds(40),
        ]);
        DB::table('qr_order_requests')->where('id', $idB)->update([
            'cashier_call_count' => 2,
            'cashier_called_at' => now()->subMinute(),
            'created_at' => $baseCreated->copy()->addSeconds(30),
        ]);
        DB::table('qr_order_requests')->where('id', $idC)->update([
            'cashier_call_count' => 2,
            'cashier_called_at' => now()->subMinute(),
            'created_at' => $baseCreated->copy()->addSeconds(10),
        ]);
        DB::table('qr_order_requests')->where('id', $idD)->update([
            'cashier_call_count' => 0,
            'cashier_called_at' => null,
            'created_at' => $baseCreated->copy()->addSeconds(1),
        ]);

        $list = $this->getJson('/api/v1/qr-orders?outletId='.$outlet->id.'&status=pending_cashier_confirmation&perPage=100')
            ->assertOk()
            ->json('data');
        $sorted = array_map(static fn (array $row): int => (int) $row['id'], $list);

        $this->assertSame([$idA, $idC, $idB, $idD], array_slice($sorted, 0, 4));
    }

    public function test_confirm_only_creates_unpaid_order_and_kitchen_ticket(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $cashier = $this->seedCashierSession($outlet->id);
        $requestId = $this->createQrRequest($outlet->id, $table->id, $menuItem->id);

        $confirm = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm", [
            'mode' => 'confirm_only',
        ]);
        $confirm->assertOk();
        $orderId = (int) $confirm->json('data.orderId');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_status' => 'unpaid',
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'decision_mode' => 'confirm_only',
        ]);
        $this->assertDatabaseHas('kitchen_tickets', [
            'order_id' => $orderId,
            'status' => 'queued',
        ]);
    }

    public function test_pay_and_confirm_creates_paid_order_and_kitchen_ticket(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $this->seedCashierSession($outlet->id);
        $requestId = $this->createQrRequest($outlet->id, $table->id, $menuItem->id);

        $confirm = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm", [
            'mode' => 'pay_and_confirm',
            'payments' => [['method' => 'cash', 'amount' => 25000]],
        ]);
        $confirm->assertOk();
        $orderId = (int) $confirm->json('data.orderId');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'decision_mode' => 'pay_and_confirm',
        ]);
        $this->assertDatabaseHas('kitchen_tickets', [
            'order_id' => $orderId,
        ]);
    }

    public function test_pending_request_prevents_duplicate_submission_for_same_table(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $this->createQrRequest($outlet->id, $table->id, $menuItem->id);

        $duplicate = $this->postJson('/api/v1/qr-orders', [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
            'customerName' => 'Guest QR 2',
            'items' => [
                ['menuItemId' => $menuItem->id, 'qty' => 1],
            ],
        ]);

        $duplicate->assertUnprocessable();
        $duplicate->assertJsonValidationErrors(['tableId']);
    }

    public function test_customer_name_is_required_on_submit(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();

        $response = $this->postJson('/api/v1/qr-orders', [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
        ]);
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['customerName']);
    }

    private function createQrRequest(int $outletId, int $tableId, int $menuItemId): int
    {
        $create = $this->postJson('/api/v1/qr-orders', [
            'outletId' => $outletId,
            'tableId' => $tableId,
            'customerName' => 'Guest QR',
            'items' => [
                ['menuItemId' => $menuItemId, 'qty' => 1],
            ],
        ]);
        $create->assertCreated();

        return (int) $create->json('data.id');
    }

    private function seedCashierSession(int $outletId)
    {
        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [$outletId]);
        PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        return $cashier;
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem} */
    private function seedQrSetup(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'QR02 Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'qr02-'.uniqid(),
        ]);

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Menu-'.uniqid(),
            'category' => 'main',
            'price' => 25000,
            'available' => true,
        ]);

        return [$outlet, $table, $menuItem];
    }
}
