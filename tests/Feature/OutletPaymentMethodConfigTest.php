<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\OutletPaymentMethodConfig;
use App\Modules\Settings\Support\PaymentMethodCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OutletPaymentMethodConfigTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        Storage::fake('public');
    }

    public function test_checkout_methods_seeds_defaults_with_cash_and_manual_qris_enabled(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();

        $response = $this->getJson('/api/v1/outlets/'.$outlet->id.'/payment-checkout-methods');
        $response->assertOk();

        $codes = collect($response->json('data'))->pluck('paymentMethodCode')->all();
        $this->assertContains('cash', $codes);
        $this->assertContains('manual_qris', $codes);
        $this->assertNotContains('gateway_qris', $codes);
    }

    public function test_gateway_qris_hidden_when_disabled(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $this->getJson('/api/v1/outlets/'.$outlet->id.'/payment-checkout-methods')->assertOk();

        OutletPaymentMethodConfig::query()
            ->where('outlet_id', $outlet->id)
            ->where('payment_method_code', 'gateway_qris')
            ->update(['enabled' => true]);

        $enabled = $this->getJson('/api/v1/outlets/'.$outlet->id.'/payment-checkout-methods');
        $enabled->assertOk();
        $this->assertContains('gateway_qris', collect($enabled->json('data'))->pluck('paymentMethodCode')->all());

        OutletPaymentMethodConfig::query()
            ->where('outlet_id', $outlet->id)
            ->where('payment_method_code', 'gateway_qris')
            ->update(['enabled' => false]);

        $disabled = $this->getJson('/api/v1/outlets/'.$outlet->id.'/payment-checkout-methods');
        $this->assertNotContains('gateway_qris', collect($disabled->json('data'))->pluck('paymentMethodCode')->all());
    }

    public function test_sync_enables_gateway_and_updates_static_qris_instructions(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $this->getJson('/api/v1/outlets/'.$outlet->id.'/payment-method-configs')->assertOk();

        $this->putJson('/api/v1/outlets/'.$outlet->id.'/payment-method-configs', [
            'configs' => [
                [
                    'paymentMethodCode' => 'manual_qris',
                    'enabled' => true,
                    'settings' => ['instructions' => 'Transfer to outlet QR only.'],
                ],
                [
                    'paymentMethodCode' => 'gateway_qris',
                    'enabled' => true,
                ],
            ],
        ])->assertOk();

        $manual = OutletPaymentMethodConfig::query()
            ->where('outlet_id', $outlet->id)
            ->where('payment_method_code', 'manual_qris')
            ->first();
        $this->assertSame('Transfer to outlet QR only.', $manual?->settings['instructions'] ?? null);
    }

    public function test_static_qris_image_upload_stores_path_in_settings(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $this->getJson('/api/v1/outlets/'.$outlet->id.'/payment-method-configs')->assertOk();

        $file = UploadedFile::fake()->image('qris.png', 200, 200);

        $response = $this->postJson('/api/v1/outlets/'.$outlet->id.'/payment-method-configs/static-qris-image', [
            'image' => $file,
        ]);
        $response->assertOk();
        $this->assertNotEmpty($response->json('data.settings.qr_image_path'));

        $config = OutletPaymentMethodConfig::query()
            ->where('outlet_id', $outlet->id)
            ->where('payment_method_code', 'manual_qris')
            ->first();
        $this->assertNotNull($config?->settings['qr_image_path'] ?? null);
        Storage::disk('public')->assertExists((string) $config?->settings['qr_image_path']);
    }

    public function test_gateway_initiation_rejected_when_gateway_disabled(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'CFG-GW-OFF');

        $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'outletId' => $outlet->id,
            'provider' => 'manual',
            'externalReference' => 'ext-cfg-gw-off',
            'idempotencyKey' => 'idem-cfg-gw-off',
            'amount' => 11000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
        ])->assertStatus(422)->assertJsonValidationErrors(['paymentMethod']);
    }

    /** @return array{0:\App\Models\User,1:\App\Models\Modules\Settings\Domain\Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = \App\Models\Modules\Settings\Domain\Outlet::query()->create([
            'name' => 'Config Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'cfg-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }

    private function createConfirmedOrder(int $outletId, int $openedByUserId, string $code): int
    {
        $table = \App\Models\Modules\Orders\Domain\RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'CFG-T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $session = \App\Models\Modules\Orders\Domain\PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $openedByUserId,
            'status' => 'open',
            'opening_cash' => 50000,
            'opened_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => $code,
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
