<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PosBootstrapApiTest extends TestCase
{
    use ProductionStationTestFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_pos_bootstrap_returns_menu_merchant_system_and_session_slices(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Bootstrap Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $this->getJson('/api/v1/pos/bootstrap?outletId='.$outlet->id.'&tenantId=1&perPage=200')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'merchant' => ['name', 'currency', 'timezone', 'language'],
                    'system' => [
                        'enableMultiPayment',
                        'stockEnforcementMode',
                        'enableQROrdering',
                    ],
                    'menuItems' => ['data', 'meta' => ['current_page', 'perPage', 'total', 'lastPage']],
                    'posSession',
                ],
            ])
            ->assertJsonPath('data.posSession', null);
    }

    public function test_pos_bootstrap_is_outlet_scoped(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowedOutlet = $this->createOutlet('Allowed Bootstrap Outlet');
        $forbiddenOutlet = $this->createOutlet('Forbidden Bootstrap Outlet');
        $this->assignUserToOutlets($user, [$allowedOutlet->id]);

        $this->getJson('/api/v1/pos/bootstrap?outletId='.$forbiddenOutlet->id)
            ->assertUnprocessable();
    }

    public function test_pos_bootstrap_requires_pos_use_permission(): void
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        $outlet = Outlet::query()->create([
            'name' => 'POS Bootstrap Permission Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pb-'.uniqid('', true),
        ]);

        $user = User::factory()->create();
        $user->outlets()->sync([(int) $outlet->id]);
        Passport::actingAs($user);

        $this->getJson('/api/v1/pos/bootstrap?outletId='.$outlet->id)
            ->assertForbidden();
    }

    public function test_pos_bootstrap_works_for_pos_use_only_user(): void
    {
        $outlet = $this->createProductionStationOutlet();
        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $this->getJson('/api/v1/pos/bootstrap?outletId='.$outlet->id.'&tenantId=1')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['merchant', 'system', 'menuItems', 'posSession'],
            ]);
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pb-'.uniqid('', true),
        ]);
    }
}
