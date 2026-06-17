<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Modules\Orders\Services\QrOrderCustomerStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderCustomerLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_under_review_with_adjustments_maps_to_adjusted_not_waiting_confirmation(): void
    {
        [$outlet, $table] = $this->seedSetup();
        $request = QrOrderRequest::query()->create([
            'outlet_id' => $outlet->id,
            'table_id' => $table->id,
            'request_code' => 'QRO-ADJUSTED1',
            'customer_name' => 'Guest',
            'status' => 'under_review',
            'expires_at' => now()->addMinutes(20),
            'review_draft' => [
                'items' => [],
                'adjustments' => [
                    ['type' => 'changed', 'original' => ['qty' => 3], 'updated' => ['qty' => 2], 'reason' => 'Sold Out'],
                ],
            ],
        ]);

        $mapped = app(QrOrderCustomerStatusService::class)->resolve($request->fresh());

        $this->assertSame('adjusted', $mapped['customerStatus']);
        $this->assertNotSame('waiting_confirmation', $mapped['customerStatus']);
    }

    public function test_table_resolve_includes_active_session(): void
    {
        [$outlet, $table, $menuItem] = $this->seedSetup();
        $table->update(['qr_public_id' => 'tbl-active-01', 'qr_enabled' => true]);

        $this->ensureQrOrderingEnabled();
        $create = $this->submitQrOrder(
            (int) $outlet->id,
            (int) $table->id,
            $table,
            [['menuItemId' => (int) $menuItem->id, 'qty' => 1]],
        )->assertCreated();

        $guestToken = $this->guestSessionForTable($table);
        $this->getJson('/api/v1/qr/tables/tbl-active-01', ['X-Qr-Guest-Session' => $guestToken])
            ->assertOk()
            ->assertJsonPath('data.activeSession.hasActiveSession', true)
            ->assertJsonPath('data.activeSession.activeQrOrder.requestCode', $create->json('data.requestCode'));
    }

    public function test_append_items_reuses_existing_qro_code(): void
    {
        [$outlet, $table, $menuItem] = $this->seedSetup();
        $this->ensureQrOrderingEnabled();
        $first = $this->submitQrOrder(
            (int) $outlet->id,
            (int) $table->id,
            $table,
            [['menuItemId' => (int) $menuItem->id, 'qty' => 1]],
        )->assertCreated();
        $code = (string) $first->json('data.requestCode');

        $second = $this->submitQrOrder(
            (int) $outlet->id,
            (int) $table->id,
            $table,
            [['menuItemId' => (int) $menuItem->id, 'qty' => 2]],
            ['appendToRequestCode' => $code],
        )->assertCreated();

        $this->assertSame($code, $second->json('data.requestCode'));
        $this->assertDatabaseCount('qr_order_requests', 1);
    }

    public function test_public_lookup_exposes_linked_pos_and_timeline(): void
    {
        [$outlet, $table, $menuItem] = $this->seedSetup();
        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [(int) $outlet->id]);
        PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $this->ensureQrOrderingEnabled();
        $create = $this->submitQrOrder(
            (int) $outlet->id,
            (int) $table->id,
            $table,
            [['menuItemId' => (int) $menuItem->id, 'qty' => 1]],
        )->assertCreated();
        $requestId = (int) $create->json('data.id');
        $requestCode = (string) $create->json('data.requestCode');

        $this->postJson("/api/v1/qr-orders/{$requestId}/confirm", ['mode' => 'confirm_only'])
            ->assertOk();

        $orderCode = (string) Order::query()->whereKey(
            (int) QrOrderRequest::query()->findOrFail($requestId)->order_id
        )->value('code');

        $this->getJson('/api/v1/public/qr-orders/'.$requestCode)
            ->assertOk()
            ->assertJsonPath('data.linkedPosOrder.orderCode', $orderCode)
            ->assertJsonPath('data.openBill.status', 'Unpaid')
            ->assertJsonStructure(['data' => ['timeline']]);
    }

    public function test_mark_served_endpoint_updates_customer_status(): void
    {
        [$outlet, $table, $menuItem] = $this->seedSetup();
        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [(int) $outlet->id]);

        PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $this->ensureQrOrderingEnabled();
        $create = $this->submitQrOrder(
            (int) $outlet->id,
            (int) $table->id,
            $table,
            [['menuItemId' => (int) $menuItem->id, 'qty' => 1]],
        )->assertCreated();
        $requestId = (int) $create->json('data.id');
        $requestCode = (string) $create->json('data.requestCode');

        $this->postJson("/api/v1/qr-orders/{$requestId}/confirm", ['mode' => 'confirm_only'])
            ->assertOk();

        $this->postJson("/api/v1/qr-orders/{$requestId}/mark-served")
            ->assertOk();

        $this->getJson('/api/v1/public/qr-orders/'.$requestCode)
            ->assertOk()
            ->assertJsonPath('data.customerStatus', 'served');
    }

    public function test_call_cashier_accepts_reason(): void
    {
        [$outlet, $table, $menuItem] = $this->seedSetup();
        $this->ensureQrOrderingEnabled();
        $requestId = (int) $this->submitQrOrder(
            (int) $outlet->id,
            (int) $table->id,
            $table,
            [['menuItemId' => (int) $menuItem->id, 'qty' => 1]],
        )->json('data.id');

        $this->postJson("/api/v1/qr-orders/{$requestId}/call-cashier", [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
            'reason' => 'request_bill',
        ])->assertOk();

        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'last_cashier_call_reason' => 'request_bill',
        ]);
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem} */
    private function seedSetup(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'life-'.uniqid(),
        ]);

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T1',
            'capacity' => 4,
            'status' => 'active',
        ]);

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Menu',
            'category' => 'main',
            'price' => 25000,
            'available' => true,
        ]);

        return [$outlet, $table, $menuItem];
    }
}
