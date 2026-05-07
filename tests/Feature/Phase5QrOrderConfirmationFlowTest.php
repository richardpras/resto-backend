<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase5QrOrderConfirmationFlowTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_pending_request_cannot_enter_kitchen_directly(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();

        $create = $this->postJson('/api/v1/qr-orders', [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
            'customerName' => 'Guest A',
            'items' => [
                ['menuItemId' => $menuItem->id, 'qty' => 2],
            ],
        ]);
        $create->assertCreated();

        $requestId = (int) $create->json('data.id');
        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'status' => 'pending_cashier_confirmation',
        ]);
        $this->assertDatabaseMissing('orders', [
            'source' => 'qr',
        ]);
        $this->assertDatabaseCount('kitchen_tickets', 0);
    }

    public function test_confirm_generates_real_order_and_kitchen_ticket(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [$outlet->id]);
        PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $requestId = $this->createQrRequest($outlet->id, $table->id, $menuItem->id);

        $confirm = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm");
        $confirm->assertOk();
        $confirm->assertJsonPath('data.status', 'confirmed');
        $orderId = (int) $confirm->json('data.orderId');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'source' => 'qr',
            'status' => 'confirmed',
            'table_id' => $table->id,
            'order_channel' => 'qr',
            'service_mode' => 'dine_in',
        ]);
        $this->assertDatabaseHas('kitchen_tickets', [
            'order_id' => $orderId,
            'outlet_id' => $outlet->id,
            'status' => 'queued',
        ]);
    }

    public function test_confirm_generates_kitchen_ticket(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [$outlet->id]);
        PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $requestId = $this->createQrRequest($outlet->id, $table->id, $menuItem->id);
        $confirm = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm");
        $confirm->assertOk();
        $orderId = (int) $confirm->json('data.orderId');

        $this->assertDatabaseHas('kitchen_tickets', [
            'order_id' => $orderId,
            'status' => 'queued',
        ]);
    }

    public function test_reject_prevents_future_confirmation(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [$outlet->id]);
        PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $requestId = $this->createQrRequest($outlet->id, $table->id, $menuItem->id);

        $reject = $this->postJson("/api/v1/qr-orders/{$requestId}/reject", [
            'reason' => 'Table closed',
        ]);
        $reject->assertOk();
        $reject->assertJsonPath('data.status', 'rejected');

        $confirm = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm");
        $confirm->assertUnprocessable();
    }

    public function test_expired_request_is_rejected_for_confirmation(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [$outlet->id]);
        PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $requestId = $this->createQrRequest($outlet->id, $table->id, $menuItem->id, 1);
        DB::table('qr_order_requests')->where('id', $requestId)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $confirm = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm");
        $confirm->assertUnprocessable();
    }

    public function test_outlet_scoping_is_enforced_for_list_and_confirm(): void
    {
        [$allowedOutlet, $allowedTable, $allowedMenuItem] = $this->seedQrSetup('P5 Allowed');
        [$forbiddenOutlet, $forbiddenTable, $forbiddenMenuItem] = $this->seedQrSetup('P5 Forbidden');

        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [$allowedOutlet->id]);
        PosSession::query()->create([
            'outlet_id' => $allowedOutlet->id,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);
        PosSession::query()->create([
            'outlet_id' => $forbiddenOutlet->id,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $allowedRequestId = $this->createQrRequest($allowedOutlet->id, $allowedTable->id, $allowedMenuItem->id);
        $forbiddenRequestId = $this->createQrRequest($forbiddenOutlet->id, $forbiddenTable->id, $forbiddenMenuItem->id);

        $list = $this->getJson('/api/v1/qr-orders?outletId='.$allowedOutlet->id.'&perPage=10');
        $list->assertOk();
        $list->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.id', (string) $allowedRequestId);

        $forbiddenList = $this->getJson('/api/v1/qr-orders?outletId='.$forbiddenOutlet->id);
        $forbiddenList->assertUnprocessable();

        $confirmForbidden = $this->postJson("/api/v1/qr-orders/{$forbiddenRequestId}/confirm");
        $confirmForbidden->assertNotFound();
    }

    private function createQrRequest(int $outletId, int $tableId, int $menuItemId, int $expiresInMinutes = 20): int
    {
        $create = $this->postJson('/api/v1/qr-orders', [
            'outletId' => $outletId,
            'tableId' => $tableId,
            'customerName' => 'Guest QR',
            'expiresInMinutes' => $expiresInMinutes,
            'items' => [
                ['menuItemId' => $menuItemId, 'qty' => 1, 'notes' => 'No chili'],
            ],
        ]);
        $create->assertCreated();

        return (int) $create->json('data.id');
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem} */
    private function seedQrSetup(string $namePrefix = 'P5 Outlet'): array
    {
        $outlet = Outlet::query()->create([
            'name' => $namePrefix.' '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower(str_replace(' ', '-', $namePrefix)).'-'.uniqid(),
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
