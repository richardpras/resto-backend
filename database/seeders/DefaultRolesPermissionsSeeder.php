<?php

namespace Database\Seeders;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Modules\UserManagement\Support\RoleHierarchy;
use Illuminate\Database\Seeder;

/**
 * Seeds the default role set (Admin, Owner, Manager, Cashier, Kitchen) with
 * hierarchy ranks and permission assignments. Mirrors WrWbFoundationSeeder role logic
 * but is customer-agnostic. Requires UserManagementPermissionsSeeder to run first.
 */
class DefaultRolesPermissionsSeeder extends Seeder
{
    private const MANAGER_PERMISSIONS = [
        'dashboard.view_own_outlet', 'pos.use', 'kitchen.use', 'menu.manage', 'inventory.manage',
        'purchase.manage', 'purchase.approve', 'promotions.manage', 'tables.view', 'tables.manage',
        'qr_orders.view', 'reports.view', 'members.manage', 'suppliers.manage', 'accounting.manage',
        'payroll.manage', 'employees.view', 'attendance.view', 'settings.view', 'settings.update',
        'users.view', 'users.create', 'users.assign_roles', 'users.update',
        'orders.recovery.read', 'orders.recovery.request', 'orders.recovery.approve', 'orders.refund.execute',
    ];

    private const CASHIER_PERMISSIONS = [
        'pos.use', 'members.manage', 'tables.view', 'qr_orders.view', 'finance.shift_close',
        'orders.recovery.read', 'orders.recovery.request',
    ];

    private const KITCHEN_PERMISSIONS = [
        'kitchen.use', 'orders.recovery.read', 'orders.recovery.request',
    ];

    public function run(): void
    {
        $permissionMap = Permission::query()->pluck('id', 'code');
        $allPermissionIds = Permission::query()->pluck('id')->all();

        // Admin and Owner are full-access roles (platform vs business owner). Restricting
        // Owner caused cascading 403s across features, so both receive every permission.
        $roleSpecs = [
            'Admin' => null,
            'Owner' => null,
            'Manager' => self::MANAGER_PERMISSIONS,
            'Cashier' => self::CASHIER_PERMISSIONS,
            'Kitchen' => self::KITCHEN_PERMISSIONS,
        ];

        foreach ($roleSpecs as $name => $codes) {
            $defaults = RoleHierarchy::defaultsForRoleName($name);

            $role = Role::query()->updateOrCreate(
                ['name' => $name],
                [
                    'description' => "Default {$name} role",
                    'staff_assignable' => $defaults['staff_assignable'],
                    'hierarchy_rank' => $defaults['hierarchy_rank'],
                ],
            );

            $ids = $codes === null
                ? $allPermissionIds
                : array_values(array_filter(array_map(
                    static fn (string $code): ?int => isset($permissionMap[$code]) ? (int) $permissionMap[$code] : null,
                    $codes,
                )));

            $role->permissions()->sync($ids);
        }
    }
}
