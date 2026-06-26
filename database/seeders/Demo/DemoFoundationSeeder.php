<?php

namespace Database\Seeders\Demo;

use App\Models\Modules\Settings\Domain\OutletReceiptSetting;
use App\Models\Modules\Settings\Domain\PaymentMethod;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\AppSettingsFromTemplateSeeder;
use Database\Seeders\SettingsDomainFromTemplateSeeder;
use Database\Seeders\TemplateAccountingSeeder;
use App\Modules\UserManagement\Support\RoleHierarchy;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoFoundationSeeder extends Seeder
{
    /** Business owner — executive visibility without platform administration (DEMO-DATA-SEEDER-01A). */
    private const OWNER_PERMISSION_CODES = [
        'dashboard.view_all_outlets',
        'dashboard.view_own_outlet',
        'dashboard.view',
        'dashboard.manage',
        'reports.view',
        'accounting.manage',
        'finance.reconcile',
        'finance.shift_close',
        'payroll.view',
        'payroll.manage',
        'inventory.manage',
        'menu.manage',
        'foodcost.view',
        'recipe.view',
        'analytics.view',
        'analytics.manage',
        'optimization.view',
        'automation.view',
        'forecasting.view',
        'purchase.manage',
        'purchase.approve',
        'promotions.manage',
        'suppliers.manage',
        'members.manage',
        'employees.view',
        'attendance.view',
        'tables.view',
        'tables.manage',
        'qr_orders.view',
        'settings.view',
        'settings.update',
        'users.view',
        'users.create',
        'users.assign_roles',
        'users.update',
    ];

    private const MANAGER_PERMISSION_CODES = [
        'dashboard.view_own_outlet', 'pos.use', 'kitchen.use', 'menu.manage', 'inventory.manage',
        'purchase.manage', 'purchase.approve', 'tables.view', 'tables.manage', 'qr_orders.view', 'reports.view',
        'members.manage', 'suppliers.manage', 'promotions.manage', 'accounting.manage',
        'payroll.manage', 'employees.view', 'attendance.view', 'settings.manage',
        'users.view', 'users.create', 'users.assign_roles', 'users.update',
    ];

    public function run(): void
    {
        if (! Permission::query()->exists()) {
            $this->call(UserManagementPermissionsSeeder::class);
        }

        if (! DB::table('app_settings')->exists()) {
            $this->call(AppSettingsFromTemplateSeeder::class);
        }

        if (! Outlet::query()->exists()) {
            $this->call(SettingsDomainFromTemplateSeeder::class);
        }

        $this->call(TemplateAccountingSeeder::class);
        $this->syncDemoOutlets();

        DB::transaction(function (): void {
            $permissionMap = Permission::query()->pluck('id', 'code');
            $allPermissionIds = Permission::query()->pluck('id')->all();

            $roleSpecs = [
                'Demo Admin' => ['permissionIds' => $allPermissionIds, ...RoleHierarchy::defaultsForRoleName('Demo Admin')],
                'Demo Owner' => ['permissionIds' => $this->permissionIds($permissionMap, self::OWNER_PERMISSION_CODES), ...RoleHierarchy::defaultsForRoleName('Demo Owner')],
                'Demo Manager' => [
                    'permissionIds' => $this->permissionIds($permissionMap, self::MANAGER_PERMISSION_CODES),
                    ...RoleHierarchy::defaultsForRoleName('Demo Manager'),
                ],
                'Demo Cashier' => [
                    'permissionIds' => $this->permissionIds($permissionMap, [
                        'pos.use', 'members.manage', 'tables.view', 'qr_orders.view',
                    ]),
                    ...RoleHierarchy::defaultsForRoleName('Demo Cashier'),
                ],
                'Demo Kitchen' => [
                    'permissionIds' => $this->permissionIds($permissionMap, ['kitchen.use']),
                    ...RoleHierarchy::defaultsForRoleName('Demo Kitchen'),
                ],
                'Demo Accountant' => [
                    'permissionIds' => $this->permissionIds($permissionMap, [
                        'accounting.manage', 'reports.view', 'finance.reconcile',
                    ]),
                    ...RoleHierarchy::defaultsForRoleName('Demo Accountant'),
                ],
                'Demo Auditor' => [
                    'permissionIds' => $this->permissionIds($permissionMap, [
                        'reports.view', 'settings.manage', 'accounting.manage',
                    ]),
                    ...RoleHierarchy::defaultsForRoleName('Demo Auditor'),
                ],
            ];

            $roleIds = [];
            foreach ($roleSpecs as $name => $spec) {
                $role = Role::query()->updateOrCreate(
                    ['name' => $name],
                    [
                        'description' => 'DEMO-DATA-SEEDER-01 role',
                        'staff_assignable' => (bool) $spec['staff_assignable'],
                        'hierarchy_rank' => (int) $spec['hierarchy_rank'],
                    ],
                );
                $role->permissions()->sync($spec['permissionIds']);
                $roleIds[$name] = $role->id;
            }

            $admin = User::query()->updateOrCreate(
                ['email' => 'admin@restohub.demo'],
                ['name' => 'Demo Admin', 'password' => 'demo123', 'pin_hash' => '0000'],
            );
            $admin->roles()->sync([$roleIds['Demo Admin']]);

            User::query()->where('email', 'owner@demo.resto.local')->update(['email' => 'owner@restohub.demo']);

            $owner = User::query()->updateOrCreate(
                ['email' => 'owner@restohub.demo'],
                ['name' => 'Demo Owner', 'password' => 'demo123', 'pin_hash' => '1234'],
            );
            $owner->roles()->sync([$roleIds['Demo Owner']]);

            foreach (DemoSeederContext::outlets() as $scopedOutlet) {
                $admin->outlets()->syncWithoutDetaching([$scopedOutlet->id]);
            }

            foreach (DemoSeederContext::OUTLET_SPECS as $key => $spec) {
                $outlet = Outlet::query()->where('code', $spec['code'])->first();
                if ($outlet === null) {
                    continue;
                }

                if (DemoSeederContext::$outletIdFilter !== null && (int) $outlet->id !== DemoSeederContext::$outletIdFilter) {
                    continue;
                }

                OutletReceiptSetting::query()->updateOrCreate(
                    ['outlet_id' => $outlet->id],
                    [
                        'receipt_header' => $spec['name'],
                        'receipt_footer' => 'Terima kasih — Demo environment',
                        'show_logo' => true,
                        'show_tax_breakdown' => true,
                    ],
                );

                $domain = $spec['domain'];
                $users = [
                    ['name' => "Manager {$spec['name']}", 'email' => "manager@{$domain}", 'pin' => '1111', 'role' => 'Demo Manager'],
                    ['name' => 'Cashier Morning', 'email' => "cashier.morning@{$domain}", 'pin' => '2221', 'role' => 'Demo Cashier'],
                    ['name' => 'Cashier Evening', 'email' => "cashier.evening@{$domain}", 'pin' => '2222', 'role' => 'Demo Cashier'],
                    ['name' => 'Kitchen Morning', 'email' => "kitchen.morning@{$domain}", 'pin' => '3331', 'role' => 'Demo Kitchen'],
                    ['name' => 'Kitchen Evening', 'email' => "kitchen.evening@{$domain}", 'pin' => '3332', 'role' => 'Demo Kitchen'],
                    ['name' => 'Accountant', 'email' => "accountant@{$domain}", 'pin' => '5555', 'role' => 'Demo Accountant'],
                    ['name' => 'Auditor', 'email' => "auditor@{$domain}", 'pin' => '6666', 'role' => 'Demo Auditor'],
                ];

                foreach ($users as $row) {
                    $user = User::query()->updateOrCreate(
                        ['email' => $row['email']],
                        ['name' => $row['name'], 'password' => 'demo123', 'pin_hash' => $row['pin']],
                    );
                    $user->roles()->sync([$roleIds[$row['role']]]);
                    $user->outlets()->syncWithoutDetaching([$outlet->id]);
                }

                $owner->outlets()->syncWithoutDetaching([$outlet->id]);

                foreach (['Cash', 'QRIS Manual', 'QRIS Online', 'Gift Card', 'Store Credit', 'Bank Transfer'] as $methodName) {
                    $slug = strtolower(str_replace(' ', '_', $methodName));
                    $id = "demo_{$key}_{$slug}";
                    PaymentMethod::query()->updateOrCreate(
                        ['id' => $id],
                        [
                            'name' => "{$methodName} - {$spec['name']}",
                            'type' => 'custom',
                            'integration' => $slug,
                            'fee' => 0,
                            'status' => 'active',
                        ],
                    );
                }

                DB::table('warehouses')->updateOrInsert(
                    ['code' => "WH-{$key}-MAIN"],
                    [
                        'outlet_id' => $outlet->id,
                        'name' => "Gudang Utama {$spec['name']}",
                        'type' => 'outlet',
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        });
    }

    /** @param  \Illuminate\Support\Collection<string, int|string>  $map */
    private function permissionIds($map, array $codes): array
    {
        $ids = [];
        foreach ($codes as $code) {
            if (isset($map[$code])) {
                $ids[] = $map[$code];
            }
        }

        return $ids;
    }

    private function syncDemoOutlets(): void
    {
        $templateCodes = ['A' => 'o-main', 'B' => 'o-branch'];

        foreach ($templateCodes as $key => $templateCode) {
            $spec = DemoSeederContext::OUTLET_SPECS[$key];
            $outlet = Outlet::query()->where('code', $templateCode)->first();
            if ($outlet === null) {
                continue;
            }

            if (DemoSeederContext::$outletIdFilter !== null && (int) $outlet->id !== DemoSeederContext::$outletIdFilter) {
                continue;
            }

            $outlet->update([
                'code' => $spec['code'],
                'name' => $spec['name'],
                'address' => $key === 'A' ? 'Jl. Sunset Beach 12, Bali' : 'Jl. Gunung Raya 8, Bandung',
                'status' => 'active',
            ]);
        }
    }
}
