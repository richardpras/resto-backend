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
            // User management (API gate)
            ['code' => 'users.view', 'name' => 'View users'],
            ['code' => 'users.create', 'name' => 'Create users'],
            ['code' => 'users.assign_roles', 'name' => 'Assign user roles'],
            ['code' => 'users.manage', 'name' => 'User management (web /template guard)'],
            ['code' => 'roles.view', 'name' => 'View roles'],
            ['code' => 'roles.create', 'name' => 'Create roles'],
            ['code' => 'roles.assign_permissions', 'name' => 'Assign role permissions'],
            ['code' => 'permissions.view', 'name' => 'View permissions'],
            ['code' => 'permissions.create', 'name' => 'Create permissions'],
            ['code' => 'settings.view', 'name' => 'View app settings'],
            ['code' => 'settings.update', 'name' => 'Update app settings'],
            ['code' => 'outlets.view_all', 'name' => 'View all outlets (API scope; tenant-wide settings/POS)'],
            // Web app route guards (mirror template PERMISSIONS in web/src/stores/authStore.ts)
            ['code' => 'dashboard.view_all_outlets', 'name' => 'Dashboard all outlets'],
            ['code' => 'dashboard.view_own_outlet', 'name' => 'Dashboard own outlet'],
            ['code' => 'pos.use', 'name' => 'Use POS'],
            ['code' => 'kitchen.use', 'name' => 'Kitchen display'],
            ['code' => 'menu.manage', 'name' => 'Manage menu'],
            ['code' => 'inventory.manage', 'name' => 'Manage inventory'],
            ['code' => 'purchase.manage', 'name' => 'Manage purchases'],
            ['code' => 'promotions.manage', 'name' => 'Manage promotions'],
            ['code' => 'payroll.manage', 'name' => 'Manage payroll (web)'],
            ['code' => 'payroll.view', 'name' => 'View payroll'],
            ['code' => 'accounting.manage', 'name' => 'Manage accounting'],
            ['code' => 'reports.view', 'name' => 'View reports'],
            ['code' => 'settings.manage', 'name' => 'Manage settings (web)'],
            ['code' => 'suppliers.manage', 'name' => 'Manage suppliers'],
            ['code' => 'members.manage', 'name' => 'Manage members'],
            ['code' => 'tables.view', 'name' => 'View tables'],
            ['code' => 'tables.manage', 'name' => 'Manage tables (floor master)'],
            ['code' => 'qr_orders.view', 'name' => 'View QR orders'],
        ];

        foreach ($definitions as $row) {
            Permission::query()->firstOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'description' => null],
            );
        }
    }
}
