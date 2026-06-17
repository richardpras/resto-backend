<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OrderSourceLinkTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_qr_order_open_in_pos_creates_link_metadata_on_order_create(): void
    {
        [$outlet, $table, $menuItem, $requestId, $code] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")->assertOk();

        $session = PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-LINK-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [[
                'id' => (string) $menuItem->id,
                'name' => $menuItem->name,
                'qty' => 1,
                'price' => 25000,
            ]],
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'payments' => [],
            'tableId' => $table->id,
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'posSessionId' => $session->id,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated();

        $create->assertJsonPath('data.orderSource.type', 'qr_order')
            ->assertJsonPath('data.orderSource.code', $code)
            ->assertJsonPath('data.orderSource.id', $requestId);

        $this->assertDatabaseHas('orders', [
            'code' => 'POS-LINK-1',
            'source_type' => 'qr_order',
            'source_id' => $requestId,
            'source_code' => $code,
        ]);
        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'order_id' => (int) $create->json('data.id'),
        ]);
    }

    public function test_reopening_linked_qr_order_returns_same_order_payload(): void
    {
        [$outlet, $table, $menuItem, $requestId, $code] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")->assertOk();

        $session = PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $orderId = (int) $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-LINK-2',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [[
                'id' => (string) $menuItem->id,
                'name' => $menuItem->name,
                'qty' => 1,
                'price' => 25000,
            ]],
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'payments' => [],
            'tableId' => $table->id,
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'posSessionId' => $session->id,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated()->json('data.id');

        $reopen = $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")
            ->assertOk()
            ->assertJsonPath('data.linkedOrder.id', $orderId)
            ->assertJsonPath('data.linkedOrder.orderNo', 'POS-LINK-2')
            ->assertJsonPath('data.loadPayload.linkedOrderId', (string) $orderId)
            ->assertJsonPath('data.loadPayload.requestCode', $code);

        $this->assertSame('POS-LINK-2', $reopen->json('data.loadPayload.linkedOrderCode'));
    }

    public function test_direct_pos_order_exposes_direct_pos_source(): void
    {
        [$outlet, $table, $menuItem] = $this->seedPendingRequest();
        unset($table);
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $session = PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-DIRECT-1',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [[
                'id' => (string) $menuItem->id,
                'name' => $menuItem->name,
                'qty' => 1,
                'price' => 25000,
            ]],
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'payments' => [],
            'serviceMode' => 'takeaway',
            'orderChannel' => 'takeaway',
            'posSessionId' => $session->id,
            'confirmedAt' => now()->toISOString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.orderSource.type', 'direct_pos')
            ->assertJsonPath('data.orderSource.label', 'Direct POS');
    }

    public function test_open_bill_resource_includes_order_source(): void
    {
        [$outlet, $table, $menuItem, $requestId, $code] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")->assertOk();
        $session = PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-OPEN-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [[
                'id' => (string) $menuItem->id,
                'name' => $menuItem->name,
                'qty' => 1,
                'price' => 25000,
            ]],
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'payments' => [],
            'tableId' => $table->id,
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'posSessionId' => $session->id,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated();

        $bill = $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)
            ->assertOk()
            ->json('data.orders.0');

        $this->assertSame('POS-OPEN-1', $bill['code']);
        $this->assertSame('qr_order', $bill['orderSource']['type']);
        $this->assertSame($code, $bill['orderSource']['code']);
    }

    public function test_qr_order_resource_exposes_linked_order(): void
    {
        [$outlet, $table, $menuItem, $requestId] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")->assertOk();
        $session = PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-LINKED-LIST',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [[
                'id' => (string) $menuItem->id,
                'name' => $menuItem->name,
                'qty' => 1,
                'price' => 25000,
            ]],
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'payments' => [],
            'tableId' => $table->id,
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'posSessionId' => $session->id,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated();

        $row = collect($this->getJson('/api/v1/qr-orders?outletId='.$outlet->id)->json('data'))
            ->firstWhere('id', (string) $requestId);

        $this->assertNotNull($row);
        $this->assertSame('POS-LINKED-LIST', $row['linkedOrder']['orderNo']);
        $this->assertSame('unpaid', $row['linkedOrder']['paymentStatus']);
    }

    public function test_order_search_by_qro_source_code(): void
    {
        [$outlet, $table, $menuItem, $requestId, $code] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")->assertOk();
        $session = PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-SEARCH-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [[
                'id' => (string) $menuItem->id,
                'name' => $menuItem->name,
                'qty' => 1,
                'price' => 25000,
            ]],
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'payments' => [],
            'tableId' => $table->id,
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'posSessionId' => $session->id,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated();

        $found = $this->getJson('/api/v1/orders?outletId='.$outlet->id.'&search='.urlencode($code))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $found);
        $this->assertSame('POS-SEARCH-1', $found[0]['code']);
    }

    public function test_qr_order_search_by_linked_pos_order_code(): void
    {
        [$outlet, $table, $menuItem, $requestId] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")->assertOk();
        $session = PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-QR-SEARCH',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [[
                'id' => (string) $menuItem->id,
                'name' => $menuItem->name,
                'qty' => 1,
                'price' => 25000,
            ]],
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'payments' => [],
            'tableId' => $table->id,
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'posSessionId' => $session->id,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated();

        $found = $this->getJson('/api/v1/qr-orders/search?code=POS-QR-SEARCH')
            ->assertOk()
            ->json('data.requestCode');

        $this->assertNotEmpty($found);
    }

    public function test_source_link_audit_events_are_recorded(): void
    {
        [$outlet, $table, $menuItem, $requestId, $code] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")->assertOk();
        $session = PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $orderId = (int) $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-AUDIT-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [[
                'id' => (string) $menuItem->id,
                'name' => $menuItem->name,
                'qty' => 1,
                'price' => 25000,
            ]],
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'payments' => [],
            'tableId' => $table->id,
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'posSessionId' => $session->id,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated()->json('data.id');

        $this->assertTrue(
            PosEventLog::query()
                ->where('event_type', 'qr_order.linked_to_pos_order')
                ->where('entity_id', $requestId)
                ->exists()
        );
        $this->assertTrue(
            PosEventLog::query()
                ->where('event_type', 'order.created_from_qr_order')
                ->where('entity_id', $orderId)
                ->exists()
        );
        $this->assertTrue(
            PosEventLog::query()
                ->where('event_type', 'qr_order.opened_in_pos')
                ->where('entity_id', $requestId)
                ->exists()
        );
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: int, 4: string} */
    private function seedPendingRequest(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Source Link Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'src-'.uniqid(),
        ]);
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'B01',
            'capacity' => 4,
            'status' => 'active',
        ]);
        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Nasi Goreng',
            'category' => 'main',
            'price' => 25000,
            'available' => true,
        ]);
        $this->ensureQrOrderingEnabled();
        $create = $this->submitQrOrder(
            (int) $outlet->id,
            (int) $table->id,
            $table,
            [['menuItemId' => (int) $menuItem->id, 'qty' => 1]],
        )->assertCreated();

        return [$outlet, $table, $menuItem, (int) $create->json('data.id'), (string) $create->json('data.requestCode')];
    }
}
