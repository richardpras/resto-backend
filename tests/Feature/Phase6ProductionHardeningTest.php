<?php

namespace Tests\Feature;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase6ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_duplicate_payment_request_is_rejected_by_idempotency_key(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createTakeawayOrder($outlet->id, 'P6-IDEMP-PAY');

        $payload = [
            'idempotencyKey' => 'pay-dup-1',
            'payments' => [
                ['method' => 'cash', 'amount' => 3000],
            ],
        ];
        $first = $this->postJson("/api/v1/orders/{$orderId}/payments", $payload);
        $first->assertOk();

        $retry = $this->postJson("/api/v1/orders/{$orderId}/payments", $payload);
        $retry->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_stale_order_update_is_rejected(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createTakeawayOrder($outlet->id, 'P6-STALE');

        $staleUpdatedAt = now()->subDay()->toISOString();

        $this->patchJson("/api/v1/orders/{$orderId}", [
            'subtotal' => 12000,
            'tax' => 1000,
            'total' => 13000,
        ])->assertOk();

        $stale = $this->patchJson("/api/v1/orders/{$orderId}", [
            'subtotal' => 9000,
            'tax' => 1000,
            'total' => 10000,
            'expectedUpdatedAt' => $staleUpdatedAt,
        ]);
        $stale->assertUnprocessable();
    }

    public function test_qr_confirm_rollback_when_pos_session_missing(): void
    {
        [$cashier, $outlet] = $this->actAsAdminWithOutlet();
        $table = $this->createTable($outlet->id, 'P6-QR');
        $menuItem = $this->createMenuItem($outlet->id);

        $requestId = $this->createQrRequest($outlet->id, $table->id, $menuItem->id);

        $confirm = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm");
        $confirm->assertUnprocessable();

        $this->assertDatabaseMissing('orders', ['source' => 'qr', 'outlet_id' => $outlet->id]);
        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'status' => 'pending_cashier_confirmation',
        ]);
        $this->assertDatabaseCount('kitchen_tickets', 0);
    }

    public function test_invalid_kitchen_transition_is_rejected(): void
    {
        [$cashier, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createTakeawayOrder($outlet->id, 'P6-KITCHEN');
        $ticketId = (int) KitchenTicket::query()->where('order_id', $orderId)->value('id');

        $ready = $this->patchJson("/api/v1/kitchen/tickets/{$ticketId}/status", [
            'status' => 'in_progress',
        ]);
        $ready->assertOk();
        $ready2 = $this->patchJson("/api/v1/kitchen/tickets/{$ticketId}/status", [
            'status' => 'ready',
        ]);
        $ready2->assertOk();

        $invalid = $this->patchJson("/api/v1/kitchen/tickets/{$ticketId}/status", [
            'status' => 'queued',
        ]);
        $invalid->assertUnprocessable();
    }

    public function test_qr_confirm_idempotent_retry_safety(): void
    {
        [$cashier, $outlet] = $this->actAsAdminWithOutlet();
        $table = $this->createTable($outlet->id, 'P6-IDEMP-QR');
        $menuItem = $this->createMenuItem($outlet->id);
        PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 50000,
            'opened_at' => now(),
        ]);

        $requestId = $this->createQrRequest($outlet->id, $table->id, $menuItem->id);
        $headers = ['Idempotency-Key' => 'qr-confirm-1'];

        $first = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm", [], $headers);
        $first->assertOk();
        $retry = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm", [], $headers);
        $retry->assertUnprocessable();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('kitchen_tickets', 1);
    }

    /** @return array{0:\App\Models\User,1:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'P6 Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p6-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }

    private function createTakeawayOrder(int $outletId, string $code): int
    {
        $response = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'takeaway',
            'items' => [['id' => '101', 'name' => 'Item', 'qty' => 1, 'price' => 11000]],
            'subtotal' => 11000,
            'tax' => 0,
            'total' => 11000,
            'payments' => [],
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
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

    private function createMenuItem(int $outletId): MenuItem
    {
        return MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'P6 Menu '.uniqid(),
            'category' => 'main',
            'price' => 12000,
            'available' => true,
        ]);
    }

    private function createQrRequest(int $outletId, int $tableId, int $menuItemId): int
    {
        $table = RestaurantTable::query()->findOrFail($tableId);
        $this->ensureQrOrderingEnabled();
        $response = $this->submitQrOrder(
            $outletId,
            $tableId,
            $table,
            [['menuItemId' => $menuItemId, 'qty' => 1]],
            ['customerName' => 'P6 Guest'],
        );
        $response->assertCreated();

        return (int) $response->json('data.id');
    }
}
