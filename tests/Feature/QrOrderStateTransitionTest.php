<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderStateTransitionTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_state_transitions_from_submitted_through_paid(): void
    {
        [$outlet, $table, $menuItem, $requestId] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'status' => 'pending_cashier_confirmation',
        ]);

        $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")->assertOk();
        $this->assertDatabaseHas('qr_order_requests', ['id' => $requestId, 'status' => 'under_review']);

        $session = PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $order = $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-'.uniqid(),
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
            'posSessionId' => $session->id,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated();

        $this->assertDatabaseHas('qr_order_requests', ['id' => $requestId, 'status' => 'confirmed']);

        $orderId = (int) $order->json('data.id');
        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 25000]],
        ])->assertOk();

        $this->assertDatabaseHas('qr_order_requests', ['id' => $requestId, 'status' => 'paid']);
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: int} */
    private function seedPendingRequest(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'State Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'state-'.uniqid(),
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
        $create = $this->postJson('/api/v1/qr-orders', [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
            'customerName' => 'Guest',
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
        ])->assertCreated();

        return [$outlet, $table, $menuItem, (int) $create->json('data.id')];
    }
}
