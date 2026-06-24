<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class TableQrGenerationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_table_qr_detail_uses_customer_app_url(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedCustomerAppUrl('https://order.example.com');

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'A01',
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
            'qr_public_id' => 'TBL_A01ABC',
            'qr_enabled' => true,
            'qr_version' => 1,
        ]);

        $this->getJson('/api/v1/tables/'.$table->id.'/qr')
            ->assertOk()
            ->assertJsonPath('data.qrUrl', 'https://order.example.com/qr/TBL_A01ABC')
            ->assertJsonPath('data.qrStatus', 'ready');

        $this->getJson('/api/v1/tables?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.qrUrl', 'https://order.example.com/qr/TBL_A01ABC');
    }

    public function test_table_qr_image_endpoint_returns_png(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedCustomerAppUrl('https://order.example.com');

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'A02',
            'capacity' => 2,
            'status' => 'active',
            'active' => true,
            'qr_public_id' => 'TBL_A02ABC',
            'qr_enabled' => true,
            'qr_version' => 1,
        ]);

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required for QR PNG generation.');
        }

        $response = $this->get('/api/v1/tables/'.$table->id.'/qr/image');
        $response->assertOk();
        $this->assertStringStartsWith("\x89PNG", $response->getContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('image/png', (string) $response->headers->get('Content-Type'));
    }

    public function test_customer_app_url_settings_api(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->patchJson('/api/v1/settings/customer-app-url', [
            'customerAppUrl' => 'https://order.restaurant.com',
        ])->assertOk()->assertJsonPath('data.customerAppUrl', 'https://order.restaurant.com');
    }

    private function seedCustomerAppUrl(string $url): void
    {
        SystemSetting::query()->updateOrCreate(['id' => 1], [
            'enable_split_bill' => true,
            'enable_multi_payment' => true,
            'confirm_before_payment' => true,
            'enable_qr_ordering' => true,
            'employee_self_service_enabled' => false,
            'customer_app_url' => $url,
        ]);
    }

    /** @return array{0:\App\Models\User,1:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'QR Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'qr-gen-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}
