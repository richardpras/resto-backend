<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PosOfflineBootstrapApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_offline_bootstrap_returns_outlet_snapshot(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Offline Bootstrap Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'off-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $response = $this->getJson('/api/v1/pos/offline-bootstrap?outletId='.(int) $outlet->id.'&tenantId=1');

        $response->assertOk()
            ->assertJsonPath('data.outletId', (int) $outlet->id)
            ->assertJsonPath('data.schemaVersion', 2)
            ->assertJsonStructure([
                'data' => [
                    'generatedAt',
                    'schemaVersion',
                    'merchant',
                    'system',
                    'menuItems',
                    'tables',
                    'checkoutMethods',
                    'receiptSettings',
                    'thermalPaperWidth',
                    'openOrders',
                    'posSession',
                    'defaultCashFloat',
                ],
            ]);
    }
}
