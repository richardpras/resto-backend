<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class XenditSandboxSimulatorTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['app.env' => 'local']);
        config(['payments.providers.xendit.secret_key' => 'xnd_test_secret']);
        config(['payments.providers.xendit.webhook_token' => 'xnd_test_callback']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_provider_simulation_dispatches_and_keeps_pending_until_webhook_arrives(): void
    {
        $user = $this->actingAsManagerWithOutletAndPermissionScope();
        Http::fake([
            'https://api.xendit.co/qr_codes/*/payments/simulate' => Http::response([
                'id' => 'qrpy_simulated_1',
                'status' => 'COMPLETED',
            ], 200),
        ]);

        $outlet = Outlet::query()->findOrFail((int) $user->outlets()->firstOrFail()->id);
        $this->seedAccountingAccounts();
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'SIM-XDT-PROVIDER');
        $tx = $this->createPendingXenditTx($orderId, (int) $outlet->id, 'sim-ext-'.uniqid());

        Passport::actingAs($user);
        $response = $this->postJson('/api/v1/payments/xendit/simulate-provider/'.$tx->id)
            ->assertOk()
            ->assertJsonPath('meta.mode', 'provider')
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(200, (int) $response->json('meta.providerResponseStatus'));
        $this->assertDatabaseHas('payment_transaction_events', [
            'payment_transaction_id' => (int) $tx->id,
            'event_type' => 'sandbox_provider_simulation_dispatched',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => (int) $tx->id,
            'status' => 'pending',
        ]);
    }

    public function test_provider_simulation_and_real_webhook_drive_paid_transition(): void
    {
        $user = $this->actingAsManagerWithOutletAndPermissionScope();
        Http::fake([
            'https://api.xendit.co/qr_codes/*/payments/simulate' => Http::response([
                'id' => 'qrpy_simulated_2',
                'status' => 'COMPLETED',
            ], 200),
        ]);
        $outlet = Outlet::query()->findOrFail((int) $user->outlets()->firstOrFail()->id);
        $this->seedAccountingAccounts();
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'SIM-XDT-WH');
        $tx = $this->createPendingXenditTx($orderId, (int) $outlet->id, 'sim-ext-'.uniqid());

        Passport::actingAs($user);
        $this->postJson('/api/v1/payments/xendit/simulate-provider/'.$tx->id)->assertOk();

        $this->postJson('/api/v1/payments/webhooks/xendit', [
            'id' => 'qrpy_simulated_2',
            'status' => 'COMPLETED',
            'updated' => now()->toIso8601String(),
            'payment_detail_source' => 'GOPAY',
            'qr_code' => [
                'id' => 'qr_2',
                'external_id' => (string) $tx->external_reference,
                'type' => 'DYNAMIC',
            ],
        ], [
            'x-callback-token' => 'xnd_test_callback',
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('payment_transactions', [
            'id' => (int) $tx->id,
            'status' => 'paid',
        ]);
        $this->assertSame(1, DB::table('journals')
            ->where('source_type', 'payment_transaction')
            ->where('source_id', (string) $tx->id)
            ->count());
    }

    public function test_provider_simulation_is_blocked_in_production_env(): void
    {
        config(['app.env' => 'production']);
        $user = $this->actingAsManagerWithOutletAndPermissionScope();
        Passport::actingAs($user);

        $this->postJson('/api/v1/payments/xendit/simulate-provider/999999')
            ->assertStatus(422);
    }

    public function test_provider_simulation_prevents_duplicate_when_paid(): void
    {
        $user = $this->actingAsManagerWithOutletAndPermissionScope();
        $outlet = Outlet::query()->findOrFail((int) $user->outlets()->firstOrFail()->id);
        $this->seedAccountingAccounts();
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'SIM-XDT-DUP');
        $tx = $this->createPendingXenditTx($orderId, (int) $outlet->id, 'sim-ext-'.uniqid(), 'paid');

        Passport::actingAs($user);
        $this->postJson('/api/v1/payments/xendit/simulate-provider/'.$tx->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['paymentId']);
    }

    public function test_provider_simulation_rejects_invalid_external_reference(): void
    {
        $user = $this->actingAsManagerWithOutletAndPermissionScope();
        $outlet = Outlet::query()->findOrFail((int) $user->outlets()->firstOrFail()->id);
        $this->seedAccountingAccounts();
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'SIM-XDT-EXT');
        $tx = $this->createPendingXenditTx($orderId, (int) $outlet->id, '');

        Passport::actingAs($user);
        $this->postJson('/api/v1/payments/xendit/simulate-provider/'.$tx->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['externalReference']);
    }

    public function test_provider_simulation_handles_timeout_failure(): void
    {
        $user = $this->actingAsManagerWithOutletAndPermissionScope();
        $outlet = Outlet::query()->findOrFail((int) $user->outlets()->firstOrFail()->id);
        $this->seedAccountingAccounts();
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'SIM-XDT-TO');
        $tx = $this->createPendingXenditTx($orderId, (int) $outlet->id, 'sim-ext-'.uniqid());

        Http::fake(function () {
            throw new ConnectionException('timeout');
        });

        Passport::actingAs($user);
        $this->postJson('/api/v1/payments/xendit/simulate-provider/'.$tx->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['provider']);

        $this->assertDatabaseHas('payment_transaction_events', [
            'payment_transaction_id' => (int) $tx->id,
            'event_type' => 'sandbox_provider_simulation_failed',
        ]);
    }

    private function actingAsManagerWithOutletAndPermissionScope(): \App\Models\User
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $managerRole = Role::query()->firstOrCreate(
            ['name' => 'manager'],
            ['description' => 'Sandbox simulation manager role']
        );
        $roleIds = $user->roles()->pluck('roles.id')->map(fn ($v): int => (int) $v)->all();
        $roleIds[] = (int) $managerRole->id;
        $user->roles()->sync(array_values(array_unique($roleIds)));

        $outlet = Outlet::query()->create([
            'name' => 'SIM Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'sim-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return $user;
    }

    private function createPendingXenditTx(int $orderId, int $outletId, string $externalReference, string $status = 'pending'): PaymentTransaction
    {
        return PaymentTransaction::query()->create([
            'order_id' => $orderId,
            'outlet_id' => $outletId,
            'provider' => 'xendit',
            'external_reference' => $externalReference,
            'idempotency_key' => 'sim-idem-'.uniqid(),
            'amount' => 103400,
            'currency' => 'IDR',
            'status' => $status,
            'payment_method' => 'qris',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAccountingAccounts(): void
    {
        DB::table('accounts')->insert([
            [
                'tenant_id' => 1,
                'code' => '1100',
                'name' => 'Cash',
                'type' => 'asset',
                'category' => 'cash_bank',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 1,
                'code' => '4100',
                'name' => 'Sales',
                'type' => 'revenue',
                'category' => 'sales_revenue',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createConfirmedOrder(int $outletId, int $openedByUserId, string $code): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'SIM-T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $session = PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $openedByUserId,
            'status' => 'open',
            'opening_cash' => 50000,
            'opened_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => $code.'-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => (int) $session->id,
            'tableId' => (int) $table->id,
            'items' => [
                ['id' => '201', 'name' => 'Item A', 'qty' => 1, 'price' => 5000],
                ['id' => '202', 'name' => 'Item B', 'qty' => 1, 'price' => 6000],
            ],
            'subtotal' => 11000,
            'tax' => 0,
            'total' => 11000,
            'payments' => [],
        ]);
        $create->assertCreated();

        return (int) $create->json('data.id');
    }
}

