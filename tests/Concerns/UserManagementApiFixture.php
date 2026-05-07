<?php

namespace Tests\Concerns;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Laravel\Passport\Passport;

trait UserManagementApiFixture
{
    protected function seedUserManagementGatePermissions(): void
    {
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    /**
     * Act as a user who can call all user/role/permission management routes (for test setup via HTTP).
     */
    protected function actingAsUserManagementApiAdministrator(): User
    {
        $this->seedUserManagementGatePermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_um_api_admin__'],
            ['description' => 'Test fixture: full user management API access'],
        );

        $role->permissions()->sync(Permission::query()->pluck('id')->all());

        $user = User::factory()->create([
            'email' => 'um-api-fixture-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        Passport::actingAs($user);

        return $user;
    }

    /**
     * @param  list<int>  $outletIds
     */
    protected function assignUserToOutlets(User $user, array $outletIds): void
    {
        $validOutletIds = Outlet::query()
            ->whereIn('id', $outletIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $user->outlets()->sync($validOutletIds);
    }
}
