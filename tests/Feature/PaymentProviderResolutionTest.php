<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PaymentProviderResolutionTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['payments.providers.manual.webhook_secret' => 'manual-secret']);
        \Illuminate\Support\Facades\Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_local_environment_allows_stub_manual_provider(): void
    {
        Config::set('app.env', 'testing');
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id);

        $response = $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'outletId' => $outlet->id,
            'provider' => 'manual',
            'externalReference' => 'ext-local-stub-1',
            'idempotencyKey' => 'idem-local-stub-1',
            'amount' => 10000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.provider', 'manual');
    }

    public function test_production_rejects_stub_manual_provider(): void
    {
        Config::set('app.env', 'production');
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id);

        $response = $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'outletId' => $outlet->id,
            'provider' => 'manual',
            'externalReference' => 'ext-prod-stub-1',
            'idempotencyKey' => 'idem-prod-stub-1',
            'amount' => 10000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['gateway']);
    }

    public function test_production_rejects_xendit_without_credentials(): void
    {
        Config::set('app.env', 'production');
        Config::set('payments.providers.xendit.secret_key', '');
        Config::set('payments.providers.xendit.webhook_token', '');
        Config::set('payments.providers.xendit.qris_callback_url', '');

        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id);

        $response = $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'outletId' => $outlet->id,
            'provider' => 'xendit',
            'externalReference' => 'ext-prod-xendit-1',
            'idempotencyKey' => 'idem-prod-xendit-1',
            'amount' => 10000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['gateway']);
    }

    /** @return array{0:\App\Models\User,1:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Payment Config Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pco-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);
        $this->enableGatewayQrisForOutlet((int) $outlet->id);

        return [$user, $outlet];
    }

    private function enableGatewayQrisForOutlet(int $outletId): void
    {
        DB::table('outlet_payment_method_configs')->upsert([
            [
                'outlet_id' => $outletId,
                'payment_method_code' => 'gateway_qris',
                'type' => 'gateway_qris',
                'provider' => 'manual',
                'enabled' => true,
                'display_order' => 10,
                'is_default' => true,
                'settings' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['outlet_id', 'payment_method_code'], ['enabled', 'provider', 'updated_at']);
    }

    private function createConfirmedOrder(int $outletId, int $openedByUserId): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'T-'.uniqid(),
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
        $tableId = (int) $table->id;
        $sessionId = (int) $session->id;

        $response = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => 'PCO-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => $sessionId,
            'tableId' => $tableId,
            'items' => [
                ['id' => '201', 'name' => 'Item A', 'qty' => 1, 'price' => 10000],
            ],
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'payments' => [],
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }
}
