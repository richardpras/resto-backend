<?php

namespace Database\Seeders;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the four template demo users (web/src + template) with distinct roles
 * and permission sets matching web PERMISSIONS / template authStore ROLE_PERMS.
 */
class TemplateDemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $allPermissionIds = Permission::query()->pluck('id')->all();

        /** @see web/src/stores/authStore.ts ROLE_PERMS.Manager */
        $managerCodes = [
            'dashboard.view_own_outlet',
            'pos.use',
            'kitchen.use',
            'menu.manage',
            'inventory.manage',
            'purchase.manage',
            'purchase.approve',
            'promotions.manage',
            'suppliers.manage',
            'members.manage',
            'tables.view',
            'tables.manage',
            'qr_orders.view',
            'reports.view',
            'orders.recovery.read',
            'orders.recovery.request',
            'orders.recovery.approve',
            'orders.refund.execute',
        ];

        /** @see web/src/stores/authStore.ts ROLE_PERMS.Cashier */
        $cashierCodes = ['pos.use', 'members.manage', 'tables.view', 'orders.recovery.read', 'orders.recovery.request'];

        /** @see web/src/stores/authStore.ts ROLE_PERMS.Kitchen */
        $kitchenCodes = ['kitchen.use', 'orders.recovery.read', 'orders.recovery.request'];

        $rolePermissionSets = [
            'Owner' => $allPermissionIds,
            'Manager' => Permission::query()->whereIn('code', $managerCodes)->pluck('id')->all(),
            'Cashier' => Permission::query()->whereIn('code', $cashierCodes)->pluck('id')->all(),
            'Kitchen' => Permission::query()->whereIn('code', $kitchenCodes)->pluck('id')->all(),
        ];

        $roleIds = [];

        foreach ($rolePermissionSets as $roleName => $permissionIds) {
            $role = Role::query()->firstOrCreate(
                ['name' => $roleName],
                ['description' => "Template role (parity with /template authStore): {$roleName}"],
            );
            $role->permissions()->sync($permissionIds);
            $roleIds[$roleName] = $role->id;
        }

        /** Same emails/passwords/names as template demo tile helpers; 4-digit screen PINs per role. */
        $users = [
            ['name' => 'John Doe', 'email' => 'owner@resto.com', 'password' => 'owner', 'role' => 'Owner', 'pin' => '1234'],
            ['name' => 'Sarah Lee', 'email' => 'manager@resto.com', 'password' => 'manager', 'role' => 'Manager', 'pin' => '2345'],
            ['name' => 'Mike Tan', 'email' => 'cashier@resto.com', 'password' => 'cashier', 'role' => 'Cashier', 'pin' => '3456'],
            ['name' => 'Anna Kitchen', 'email' => 'kitchen@resto.com', 'password' => 'kitchen', 'role' => 'Kitchen', 'pin' => '4567'],
        ];

        foreach ($users as $row) {
            // Password and PIN are hashed via User model casts (`password`, `pin_hash` => `hashed`).
            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'password' => $row['password'],
                    'pin_hash' => $row['pin'],
                ],
            );
            $user->roles()->sync([$roleIds[$row['role']]]);
        }
    }
}
