<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OutletAuthorizationRemediationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_outlet_scoped_user_cannot_access_other_outlet_trial_balance(): void
    {
        $outletA = $this->makeOutlet('A');
        $outletB = $this->makeOutlet('B');
        $this->actingAsOutletScopedUser([(int) $outletA->id]);

        $this->getJson('/api/v1/reports/trial-balance?outletId='.$outletA->id)->assertOk();

        $this->getJson('/api/v1/reports/trial-balance?outletId='.$outletB->id)->assertUnprocessable();
    }

    /** @param list<int> $outletIds */
    protected function actingAsOutletScopedUser(array $outletIds): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::firstOrCreate(['name' => '__test_outlet_scoped__'], ['description' => 'test']);
        $role->permissions()->sync(Permission::whereIn('code', ['reports.view', 'accounting.manage', 'pos.use'])->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        $this->assignUserToOutlets($user, $outletIds);
        Passport::actingAs($user);

        return $user;
    }

    private function makeOutlet(string $suffix): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Auth-'.$suffix.'-'.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'auth-'.$suffix.'-'.uniqid(),
        ]);
    }
}
