<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Orders\Services\QrOrderCustomerStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderCustomerStatusMappingTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_pending_maps_to_pending_review_label(): void
    {
        $request = $this->makeRequest('pending_cashier_confirmation');
        $mapped = app(QrOrderCustomerStatusService::class)->resolve($request, 'id');

        $this->assertSame('pending_review', $mapped['customerStatus']);
        $this->assertSame('Menunggu review kasir', $mapped['customerStatusLabel']);
        $this->assertSame(0, $mapped['timelineStep']);
    }

    public function test_rejected_maps_to_cancelled(): void
    {
        $request = $this->makeRequest('rejected');
        $mapped = app(QrOrderCustomerStatusService::class)->resolve($request, 'id');

        $this->assertSame('cancelled', $mapped['customerStatus']);
        $this->assertSame('Dibatalkan', $mapped['customerStatusLabel']);
        $this->assertTrue($mapped['isTerminal']);
    }

    public function test_confirmed_order_kitchen_preparing_maps_to_cooking(): void
    {
        $request = $this->makeRequest('confirmed', 'preparing');
        $mapped = app(QrOrderCustomerStatusService::class)->resolve($request, 'id');

        $this->assertSame('cooking', $mapped['customerStatus']);
        $this->assertSame('Sedang dimasak', $mapped['customerStatusLabel']);
        $this->assertSame(2, $mapped['timelineStep']);
    }

    public function test_confirmed_order_kitchen_in_progress_maps_to_cooking(): void
    {
        $request = $this->makeRequest('confirmed', 'in_progress');
        $mapped = app(QrOrderCustomerStatusService::class)->resolve($request, 'id');

        $this->assertSame('cooking', $mapped['customerStatus']);
        $this->assertSame('Sedang dimasak', $mapped['customerStatusLabel']);
        $this->assertSame(2, $mapped['timelineStep']);
    }

    public function test_confirmed_with_adjustment_log_still_follows_kitchen_status(): void
    {
        [$outlet, $table, $menuItem] = $this->seedSetup();
        $request = QrOrderRequest::query()->create([
            'outlet_id' => $outlet->id,
            'table_id' => $table->id,
            'request_code' => 'QRO-'.strtoupper(uniqid()),
            'customer_name' => 'Guest',
            'status' => 'confirmed',
            'adjustment_log' => [[
                'at' => now()->toIso8601String(),
                'byUserId' => 1,
                'summary' => [['type' => 'modified', 'name' => 'Menu']],
            ]],
            'expires_at' => now()->addMinutes(20),
        ]);

        $order = Order::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'ORD-'.uniqid(),
            'source' => 'qr',
            'order_channel' => 'qr',
            'service_mode' => 'dine_in',
            'order_type' => 'Dine In',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'kitchen_status' => 'cooking',
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'table_id' => $table->id,
        ]);
        $request->update(['order_id' => $order->id, 'confirmed_at' => now()]);

        $mapped = app(QrOrderCustomerStatusService::class)->resolve($request->fresh(['order.items']), 'id');

        $this->assertSame('cooking', $mapped['customerStatus']);
    }

    public function test_confirmed_order_kitchen_ready_maps_to_ready(): void
    {
        $request = $this->makeRequest('confirmed', 'ready');
        $mapped = app(QrOrderCustomerStatusService::class)->resolve($request, 'id');

        $this->assertSame('ready', $mapped['customerStatus']);
        $this->assertSame('Siap diantar', $mapped['customerStatusLabel']);
        $this->assertSame(3, $mapped['timelineStep']);
    }

    public function test_confirmed_order_served_maps_to_terminal_served(): void
    {
        $request = $this->makeRequest('confirmed', 'served');
        $mapped = app(QrOrderCustomerStatusService::class)->resolve($request, 'id');

        $this->assertSame('served', $mapped['customerStatus']);
        $this->assertSame('Sudah diantar', $mapped['customerStatusLabel']);
        $this->assertFalse($mapped['isTerminal']);
    }

    public function test_public_lookup_uses_customer_labels(): void
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

        $create = $this->postJson('/api/v1/qr-orders', [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
            'customerName' => 'Guest',
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
        ])->assertCreated();
        $requestId = (int) $create->json('data.id');
        $requestCode = (string) $create->json('data.requestCode');

        $this->postJson("/api/v1/qr-orders/{$requestId}/confirm", ['mode' => 'confirm_only'])
            ->assertOk();

        $orderId = (int) QrOrderRequest::query()->findOrFail($requestId)->order_id;
        Order::query()->whereKey($orderId)->update(['kitchen_status' => 'preparing']);

        $this->getJson('/api/v1/public/qr-orders/'.$requestCode, ['Accept-Language' => 'id'])
            ->assertOk()
            ->assertJsonPath('data.customerStatus', 'cooking')
            ->assertJsonPath('data.customerStatusLabel', 'Sedang dimasak');
    }

    private function makeRequest(string $status, ?string $kitchenStatus = null): QrOrderRequest
    {
        [$outlet, $table, $menuItem] = $this->seedSetup();
        $request = QrOrderRequest::query()->create([
            'outlet_id' => $outlet->id,
            'table_id' => $table->id,
            'request_code' => 'QRO-'.strtoupper(uniqid()),
            'customer_name' => 'Guest',
            'status' => $status,
            'expires_at' => now()->addMinutes(20),
        ]);

        if ($kitchenStatus !== null) {
            $order = Order::query()->create([
                'outlet_id' => $outlet->id,
                'code' => 'ORD-'.uniqid(),
                'source' => 'qr',
                'order_channel' => 'qr',
                'service_mode' => 'dine_in',
                'order_type' => 'Dine In',
                'status' => 'confirmed',
                'payment_status' => 'unpaid',
                'kitchen_status' => $kitchenStatus,
                'subtotal' => 25000,
                'tax' => 0,
                'total' => 25000,
                'table_id' => $table->id,
            ]);
            $request->update(['order_id' => $order->id, 'confirmed_at' => now()]);
        }

        return $request->fresh(['order', 'items.menuItem']);
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
            'code' => 'map-'.uniqid(),
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
