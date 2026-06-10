<?php

namespace Tests\Concerns;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

trait BugReportTestFixture
{
    protected function seedBugReportPermissions(): void
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    protected function createBugReportOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Bug Report Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'bug-'.uniqid('', true),
        ]);
    }

    protected function createUserWithPermission(string $permissionCode, Outlet $outlet): User
    {
        $this->seedBugReportPermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_bug_'.$permissionCode.'__'],
            ['description' => 'Test '.$permissionCode],
        );
        $permission = Permission::query()->where('code', $permissionCode)->firstOrFail();
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        $user->outlets()->sync([(int) $outlet->id]);

        return $user;
    }

    protected function actingAsBugReporter(Outlet $outlet): User
    {
        $this->seedBugReportPermissions();
        $user = User::factory()->create();
        $user->outlets()->sync([(int) $outlet->id]);
        Passport::actingAs($user);

        return $user;
    }
}
