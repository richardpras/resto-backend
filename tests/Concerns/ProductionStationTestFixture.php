<?php

namespace Tests\Concerns;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

trait ProductionStationTestFixture
{
    protected function seedProductionStationPermissions(): void
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    protected function createProductionStationOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Production Station Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ps-'.uniqid('', true),
        ]);
    }

    protected function createUserWithPermission(string $permissionCode, Outlet $outlet): User
    {
        $this->seedProductionStationPermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_ps_'.$permissionCode.'__'],
            ['description' => 'Test '.$permissionCode],
        );
        $permission = Permission::query()->where('code', $permissionCode)->firstOrFail();
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        $user->outlets()->sync([(int) $outlet->id]);

        return $user;
    }

    protected function actingAsProductionStationManager(Outlet $outlet): User
    {
        $user = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($user);

        return $user;
    }
}
