<?php

namespace Database\Seeders;

use App\Http\Middleware\EnsurePermission;
use App\Models\Modules\UserManagement\Domain\Permission;
use Illuminate\Database\Seeder;

/**
 * Seeds canonical user/role/permission admin gate codes used by {@see EnsurePermission}.
 */
class UserManagementPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            ['code' => 'users.view', 'name' => 'View users'],
            ['code' => 'users.create', 'name' => 'Create users'],
            ['code' => 'users.assign_roles', 'name' => 'Assign user roles'],
            ['code' => 'roles.view', 'name' => 'View roles'],
            ['code' => 'roles.create', 'name' => 'Create roles'],
            ['code' => 'roles.assign_permissions', 'name' => 'Assign role permissions'],
            ['code' => 'permissions.view', 'name' => 'View permissions'],
            ['code' => 'permissions.create', 'name' => 'Create permissions'],
            ['code' => 'settings.view', 'name' => 'View app settings'],
            ['code' => 'settings.update', 'name' => 'Update app settings'],
        ];

        foreach ($definitions as $row) {
            Permission::query()->firstOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'description' => null],
            );
        }
    }
}
