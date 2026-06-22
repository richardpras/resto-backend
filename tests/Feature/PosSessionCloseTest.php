<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Settings\Domain\Outlet;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PosSessionCloseTest extends TestCase
{
    use ProductionStationTestFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    public function test_open_session_defaults_opening_cash_from_outlet_float(): void
    {
        $outlet = $this->createOutletWithFloat(500000);
        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
        ])->assertCreated()
            ->assertJsonPath('data.openingCash', 500000);
    }

    public function test_close_preview_returns_drawer_reconciliation(): void
    {
        $outlet = $this->createOutletWithFloat(500000);
        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $open = $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 500000,
        ])->assertCreated();

        $sessionId = (int) $open->json('data.id');

        $this->getJson('/api/v1/pos-sessions/'.$sessionId.'/close-preview')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'sessionId',
                    'outletId',
                    'defaultCashFloat',
                    'drawerReconciliation' => ['openingCash', 'expected'],
                ],
            ])
            ->assertJsonPath('data.drawerReconciliation.openingCash', 500000);
    }

    public function test_close_session_stores_expected_actual_and_variance(): void
    {
        $outlet = $this->createOutletWithFloat(500000);
        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $sessionId = (int) $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 500000,
        ])->json('data.id');

        $this->postJson('/api/v1/pos-sessions/'.$sessionId.'/close', [
            'actualCash' => 500000,
        ])->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.actualCash', 500000)
            ->assertJsonPath('data.expectedCash', 500000)
            ->assertJsonPath('data.cashVariance', 0);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $sessionId,
            'status' => 'closed',
            'expected_cash' => 500000,
            'actual_cash' => 500000,
            'cash_variance' => 0,
        ]);
    }

    public function test_paid_order_in_closed_session_cannot_be_cancelled(): void
    {
        $outlet = $this->createOutletWithFloat(500000);
        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $sessionId = (int) $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 500000,
        ])->json('data.id');

        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'pos_session_id' => $sessionId,
            'code' => 'ORD-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'dine_in',
            'status' => 'completed',
            'payment_status' => 'paid',
            'kitchen_status' => 'queued',
            'subtotal' => 50000,
            'tax' => 0,
            'total' => 50000,
            'paid_total' => 50000,
            'balance_due' => 0,
            'is_posted' => false,
        ]);

        $this->postJson('/api/v1/pos-sessions/'.$sessionId.'/close', [
            'actualCash' => 550000,
        ])->assertOk();

        $this->patchJson('/api/v1/orders/'.$order->id.'/status', [
            'status' => 'cancelled',
        ])->assertUnprocessable();
    }

    public function test_unpaid_order_released_from_session_on_close_and_remains_editable(): void
    {
        $outlet = $this->createOutletWithFloat(500000);
        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $sessionId = (int) $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 500000,
        ])->json('data.id');

        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'pos_session_id' => $sessionId,
            'code' => 'ORD-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'dine_in',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'kitchen_status' => 'queued',
            'subtotal' => 30000,
            'tax' => 0,
            'total' => 30000,
            'paid_total' => 0,
            'balance_due' => 30000,
            'is_posted' => false,
        ]);

        $this->postJson('/api/v1/pos-sessions/'.$sessionId.'/close', [
            'actualCash' => 500000,
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'pos_session_id' => null,
        ]);

        $this->patchJson('/api/v1/orders/'.$order->id, [
            'customerName' => 'Carry Forward Guest',
        ])->assertOk()
            ->assertJsonPath('data.customerName', 'Carry Forward Guest');
    }

    public function test_current_session_includes_default_cash_float_meta(): void
    {
        $outlet = $this->createOutletWithFloat(750000);
        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $this->getJson('/api/v1/pos-sessions/current?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('meta.defaultCashFloat', 750000);
    }

    private function createOutletWithFloat(float $float): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Close Shift Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'psc-'.uniqid('', true),
            'default_cash_float' => $float,
        ]);
    }
}
