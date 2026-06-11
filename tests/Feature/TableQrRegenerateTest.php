<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class TableQrRegenerateTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_regenerate_invalidates_old_qr_token(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        SystemSetting::query()->updateOrCreate(['id' => 1], [
            'enable_split_bill' => true,
            'enable_multi_payment' => true,
            'confirm_before_payment' => true,
            'enable_qr_ordering' => true,
            'employee_self_service_enabled' => false,
            'customer_app_url' => 'https://order.example.com',
        ]);

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'B01',
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
        ]);

        $generated = $this->postJson('/api/v1/tables/'.$table->id.'/qr/generate')->assertOk();
        $oldPublicId = (string) $generated->json('data.qrPublicId');

        $this->getJson('/api/v1/qr/tables/'.$oldPublicId)->assertOk();

        $regenerated = $this->postJson('/api/v1/tables/'.$table->id.'/qr/regenerate')->assertOk();
        $newPublicId = (string) $regenerated->json('data.qrPublicId');
        $this->assertNotSame($oldPublicId, $newPublicId);

        $this->getJson('/api/v1/qr/tables/'.$oldPublicId)
            ->assertNotFound()
            ->assertJsonPath('code', 'qr_not_found');
        $this->getJson('/api/v1/qr/tables/'.$newPublicId)->assertOk();
    }

    /** @return array{0:\App\Models\User,1:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'QR Regen Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'qr-regen-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}
