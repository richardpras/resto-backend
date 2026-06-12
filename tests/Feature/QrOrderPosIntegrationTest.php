<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderPosIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_open_in_pos_returns_load_payload_and_marks_under_review(): void
    {
        [$outlet, , , $requestId, $code] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $response = $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")
            ->assertOk()
            ->assertJsonPath('data.posSession.sessionType', 'qr_order')
            ->assertJsonPath('data.posSession.sourceOrderCode', $code)
            ->assertJsonPath('data.loadPayload.requestCode', $code);

        $this->assertSame('under_review', $response->json('data.request.status'));
        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'status' => 'under_review',
        ]);
        $this->assertTrue(
            PosEventLog::query()->where('entity_id', $requestId)->where('event_type', 'qr_order.opened_in_pos')->exists()
        );
    }

    public function test_pos_order_create_links_qr_request_and_confirms(): void
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

        $create = $this->postJson('/api/v1/orders', [
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
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'posSessionId' => $session->id,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated();

        $orderId = (int) $create->json('data.id');
        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'status' => 'confirmed',
            'order_id' => $orderId,
        ]);
        $this->assertTrue(
            PosEventLog::query()->where('entity_id', $requestId)->where('event_type', 'qr_order.confirmed')->exists()
        );
    }

    public function test_list_supports_under_review_status_filter(): void
    {
        [$outlet, , , $requestId, $code] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")->assertOk();

        $list = $this->getJson('/api/v1/qr-orders?outletId='.$outlet->id.'&status=under_review')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $list);
        $this->assertSame($code, $list[0]['requestCode']);
        $this->assertSame('under_review', $list[0]['status']);
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: int, 4: string} */
    private function seedPendingRequest(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'POS Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pos-'.uniqid(),
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

        return [$outlet, $table, $menuItem, (int) $create->json('data.id'), (string) $create->json('data.requestCode')];
    }
}
