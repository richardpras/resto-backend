<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderScannerSupportTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_scan_endpoint_accepts_qr_payload(): void
    {
        [$requestCode, $user] = $this->createRequestCode();

        $this->postJson('/api/v1/qr-orders/scan', [
            'code' => 'https://demo.test/qr/order/'.$requestCode,
        ])
            ->assertOk()
            ->assertJsonPath('data.requestCode', $requestCode);
    }

    /** @return array{0: string, 1: \App\Models\User} */
    private function createRequestCode(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Scan Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'scan-'.uniqid(),
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
            'name' => 'Tea',
            'category' => 'drinks',
            'price' => 10000,
            'available' => true,
        ]);
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->ensureQrOrderingEnabled();
        $requestCode = (string) $this->submitQrOrder(
            (int) $outlet->id,
            (int) $table->id,
            $table,
            [['menuItemId' => (int) $menuItem->id, 'qty' => 1]],
        )->assertCreated()->json('data.requestCode');

        return [$requestCode, $user];
    }
}
