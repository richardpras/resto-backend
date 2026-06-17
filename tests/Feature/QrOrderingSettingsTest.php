<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderingSettingsTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_system_settings_default_enable_call_cashier_true(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/system-settings')
            ->assertOk()
            ->assertJsonPath('data.enableCallCashier', true);
    }

    public function test_admin_can_disable_enable_call_cashier(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();

        $this->patchJson('/api/v1/system-settings', [
            'enableSplitBill' => true,
            'enableMultiPayment' => true,
            'confirmBeforePayment' => true,
            'enableQROrdering' => true,
            'enableCallCashier' => false,
        ])->assertOk()->assertJsonPath('data.enableCallCashier', false);

        $this->assertDatabaseHas('system_settings', [
            'enable_call_cashier' => false,
        ]);
    }

    public function test_public_qr_order_lookup_exposes_qr_ordering_config(): void
    {
        [$outlet, $table, $menuItem, $requestCode] = $this->seedSubmittedRequest();

        $this->getJson('/api/v1/public/qr-orders/'.$requestCode)
            ->assertOk()
            ->assertJsonPath('data.qrOrdering.enableCallCashier', true);
    }

    public function test_public_lookup_reflects_disabled_call_cashier_setting(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'enable_split_bill' => true,
                'enable_multi_payment' => true,
                'confirm_before_payment' => true,
                'enable_qr_ordering' => true,
                'enable_call_cashier' => false,
            ],
        );

        [, , , $requestCode] = $this->seedSubmittedRequest();

        $this->getJson('/api/v1/public/qr-orders/'.$requestCode)
            ->assertOk()
            ->assertJsonPath('data.qrOrdering.enableCallCashier', false);
    }

    public function test_call_cashier_endpoint_rejects_when_setting_disabled(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'enable_split_bill' => true,
                'enable_multi_payment' => true,
                'confirm_before_payment' => true,
                'enable_qr_ordering' => true,
                'enable_call_cashier' => false,
            ],
        );

        [$outlet, $table, , $requestCode, $requestId] = $this->seedSubmittedRequest();

        $this->postJson("/api/v1/qr-orders/{$requestId}/call-cashier", [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['callCashier']);
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: string, 4: int} */
    private function seedSubmittedRequest(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'QR Settings Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'qr-set-'.uniqid(),
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

        $requestId = (int) $create->json('data.id');
        $requestCode = (string) $create->json('data.requestCode');

        return [$outlet, $table, $menuItem, $requestCode, $requestId];
    }
}
