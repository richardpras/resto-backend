<?php

namespace Database\Seeders\CustomerDemo;

use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuCategoryPrinterMapping;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\BankAccount;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletPaymentMethodConfig;
use App\Models\Modules\Settings\Domain\OutletReceiptSetting;
use App\Models\Modules\Settings\Domain\PaymentMethod;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\Production\Services\ProductionStationProvisioner;
use App\Modules\Settings\Services\OutletPaymentMethodConfigService;
use App\Modules\UserManagement\Support\RoleHierarchy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WrWbFoundationSeeder extends Seeder
{
    private const OWNER_PERMISSIONS = [
        'dashboard.view_all_outlets', 'dashboard.view_own_outlet', 'dashboard.view', 'dashboard.manage',
        'reports.view', 'accounting.manage', 'finance.reconcile', 'finance.shift_close',
        'payroll.view', 'payroll.manage', 'inventory.manage', 'menu.manage', 'foodcost.view',
        'recipe.view', 'analytics.view', 'analytics.manage', 'optimization.view', 'automation.view',
        'forecasting.view', 'purchase.manage', 'purchase.approve', 'promotions.manage',
        'suppliers.manage', 'members.manage', 'employees.view', 'attendance.view',
        'tables.view', 'tables.manage', 'qr_orders.view', 'settings.view', 'settings.update',
        'users.view', 'users.create', 'users.assign_roles', 'users.update',
    ];

    private const MANAGER_PERMISSIONS = [
        'dashboard.view_own_outlet', 'pos.use', 'kitchen.use', 'menu.manage', 'inventory.manage',
        'purchase.manage', 'purchase.approve', 'tables.view', 'tables.manage', 'qr_orders.view',
        'reports.view', 'members.manage', 'suppliers.manage', 'accounting.manage', 'finance.shift_close',
        'payroll.manage', 'employees.view', 'attendance.view', 'settings.view', 'settings.update',
        'users.view', 'users.create', 'users.assign_roles', 'users.update',
    ];

    public function run(): void
    {
        if (! DB::table('app_settings')->exists()) {
            $this->call(AppSettingsFromTemplateSeeder::class);
        }

        DB::transaction(function (): void {
            $this->seedSystemSettings();
            $this->seedRolesAndUsers();
            $this->seedOutlet();
            $this->seedPaymentConfig();
            $this->seedBankAccounts();
            $this->seedWarehouse();
            $this->seedSupplier();
        });
    }

    private function seedSystemSettings(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'enable_split_bill' => true,
                'enable_multi_payment' => true,
                'confirm_before_payment' => false,
                'enable_qr_ordering' => true,
                'stock_enforcement_mode' => 'deferred',
                'enforce_stock_on_sale' => false,
                'allow_negative_stock' => true,
            ],
        );
    }

    private function seedRolesAndUsers(): void
    {
        $permissionMap = Permission::query()->pluck('id', 'code');
        $allPermissionIds = Permission::query()->pluck('id')->all();

        $roleSpecs = [
            'WR WB Admin' => ['permissions' => null, ...RoleHierarchy::defaultsForRoleName('WR WB Admin')],
            'WR WB Owner' => ['permissions' => self::OWNER_PERMISSIONS, ...RoleHierarchy::defaultsForRoleName('WR WB Owner')],
            'WR WB Manager' => [
                'permissions' => self::MANAGER_PERMISSIONS,
                ...RoleHierarchy::defaultsForRoleName('WR WB Manager'),
            ],
            'WR WB Cashier' => [
                'permissions' => ['pos.use', 'members.manage', 'tables.view', 'qr_orders.view'],
                ...RoleHierarchy::defaultsForRoleName('WR WB Cashier'),
            ],
            'WR WB Kitchen' => [
                'permissions' => ['kitchen.use'],
                ...RoleHierarchy::defaultsForRoleName('WR WB Kitchen'),
            ],
        ];

        $roleIds = [];
        foreach ($roleSpecs as $name => $spec) {
            $role = Role::query()->updateOrCreate(
                ['name' => $name],
                [
                    'description' => 'WR WB customer demo role',
                    'staff_assignable' => (bool) $spec['staff_assignable'],
                    'hierarchy_rank' => (int) $spec['hierarchy_rank'],
                ],
            );
            $codes = $spec['permissions'];
            $ids = $codes === null
                ? $allPermissionIds
                : array_values(array_filter(array_map(
                    static fn (string $code): ?int => isset($permissionMap[$code]) ? (int) $permissionMap[$code] : null,
                    $codes,
                )));
            $role->permissions()->sync($ids);
            $roleIds[$name] = $role->id;
        }

        foreach (CustomerDemoContext::USER_SPECS as $key => $spec) {
            $user = User::query()->updateOrCreate(
                ['email' => $spec['email']],
                ['name' => $spec['name'], 'password' => 'demo123', 'pin_hash' => $spec['pin']],
            );
            $user->roles()->sync([$roleIds[$spec['role']]]);
            CustomerDemoContext::$users[$key] = $user;
        }
    }

    private function seedOutlet(): void
    {
        $outlet = Outlet::query()->updateOrCreate(
            ['code' => CustomerDemoContext::OUTLET_CODE],
            [
                'name' => CustomerDemoContext::OUTLET_NAME,
                'address' => 'Jl. Demo WR WB No. 1, Jakarta',
                'phone' => '021-5550100',
                'manager' => 'Manager WR WB',
                'status' => 'active',
                'order_prefix' => 'WRWB',
            ],
        );

        CustomerDemoContext::$outlet = $outlet;

        foreach (CustomerDemoContext::$users as $user) {
            $user->outlets()->syncWithoutDetaching([(int) $outlet->id]);
        }

        OutletReceiptSetting::query()->updateOrCreate(
            ['outlet_id' => $outlet->id],
            [
                'receipt_header' => CustomerDemoContext::OUTLET_NAME,
                'receipt_footer' => 'Terima kasih — WR WB Demo',
                'show_logo' => true,
                'show_tax_breakdown' => true,
            ],
        );

        AccountingSetting::query()->updateOrCreate(
            ['tenant_id' => null, 'outlet_id' => $outlet->id],
            ['revenue_posting_mode' => AccountingSetting::MODE_REALTIME],
        );

        app(ProductionStationProvisioner::class)->ensureForOutlet($outlet, ['kitchen', 'bar', 'cashier']);

        for ($n = 1; $n <= 10; $n++) {
            $code = 'WRWB-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            RestaurantTable::query()->updateOrCreate(
                ['outlet_id' => $outlet->id, 'code' => $code],
                [
                    'qr_public_id' => 'wrwb-demo-'.strtolower($code),
                    'name' => "Meja {$n}",
                    'capacity' => $n <= 6 ? 4 : 6,
                    'zone' => $n <= 5 ? 'Indoor' : 'Outdoor',
                    'status' => 'active',
                    'active' => true,
                    'qr_enabled' => true,
                    'qr_version' => 1,
                ],
            );
        }

        $this->seedHardwareBridge((int) $outlet->id);
        $this->seedPrinterProfiles((int) $outlet->id);
        $this->seedMenuCategories((int) $outlet->id);
    }

    private function seedHardwareBridge(int $outletId): void
    {
        HardwareBridgeDevice::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'device_key' => 'bridge-wrwb-main'],
            [
                'display_label' => 'WR WB Bridge',
                'capabilities' => ['lan', 'print-queue'],
                'metadata' => ['deployment' => ['headless' => true, 'serviceMode' => 'demo']],
                'status' => 'active',
                'last_seen_at' => now(),
                'reconnect_count' => 0,
            ],
        );
    }

    private function seedPrinterProfiles(int $outletId): void
    {
        $deviceKey = 'bridge-wrwb-main';
        $profiles = [
            ['code' => 'WRWB-CASHIER', 'name' => 'Kasir Receipt', 'station' => 'cashier', 'lanIp' => '10.10.1.20'],
            ['code' => 'WRWB-KITCHEN', 'name' => 'Kitchen Ticket', 'station' => 'kitchen', 'lanIp' => '10.10.1.21'],
            ['code' => 'WRWB-BAR', 'name' => 'Bar Ticket', 'station' => 'bar', 'lanIp' => '10.10.1.22'],
        ];

        $profileIds = [];
        foreach ($profiles as $row) {
            $lanIp = (string) $row['lanIp'];
            $profile = PrinterProfile::query()->updateOrCreate(
                ['outlet_id' => $outletId, 'code' => $row['code']],
                [
                    'name' => $row['name'],
                    'station' => $row['station'],
                    'connection_type' => 'bridge',
                    'device_identifier' => "bridge://{$outletId}/{$row['code']}",
                    'ip_address' => $lanIp,
                    'endpoint' => "tcp://{$lanIp}:9100",
                    'is_active' => true,
                    'health_status' => 'healthy',
                    'queue_state' => 'idle',
                    'meta' => [
                        'bridge' => ['deviceKey' => $deviceKey],
                        'lan' => ['ip' => $lanIp, 'port' => 9100],
                    ],
                ],
            );
            $profileIds[$row['station']] = $profile->id;
        }

        foreach (['Food' => 'kitchen', 'Beverage' => 'bar', 'Dessert' => 'kitchen'] as $categoryName => $station) {
            $category = MenuCategory::query()
                ->where('code', 'WRWB-'.strtoupper(str_replace(' ', '_', $categoryName)))
                ->first();
            if ($category === null || ! isset($profileIds[$station])) {
                continue;
            }
            MenuCategoryPrinterMapping::query()->updateOrCreate(
                ['menu_category_id' => $category->id, 'outlet_id' => $outletId],
                ['printer_profile_id' => $profileIds[$station], 'is_active' => true],
            );
        }
    }

    private function seedMenuCategories(int $outletId): void
    {
        $categories = [
            ['name' => 'Food', 'nameEn' => 'Food', 'nameId' => 'Makanan', 'sort' => 1],
            ['name' => 'Beverage', 'nameEn' => 'Beverage', 'nameId' => 'Minuman', 'sort' => 2],
            ['name' => 'Dessert', 'nameEn' => 'Dessert', 'nameId' => 'Pencuci Mulut', 'sort' => 3],
        ];

        foreach ($categories as $row) {
            MenuCategory::query()->updateOrCreate(
                ['code' => 'WRWB-'.strtoupper(str_replace(' ', '_', $row['name']))],
                [
                    'tenant_id' => CustomerDemoContext::TENANT_ID,
                    'name' => $row['name'],
                    'name_en' => $row['nameEn'],
                    'name_id' => $row['nameId'],
                    'is_active' => true,
                    'sort_order' => $row['sort'],
                ],
            );
        }
    }

    private function seedPaymentConfig(): void
    {
        $outletId = CustomerDemoContext::outletId();
        $service = app(OutletPaymentMethodConfigService::class);
        $service->ensureDefaultsForOutlet($outletId);

        $configs = [
            'cash' => ['enabled' => true, 'is_default' => true],
            'manual_qris' => ['enabled' => true, 'is_default' => false],
            'gateway_qris' => ['enabled' => false, 'is_default' => false],
            'gateway_ewallet' => ['enabled' => false, 'is_default' => false],
            'manual_transfer' => ['enabled' => false, 'is_default' => false],
            'gift_card' => ['enabled' => false, 'is_default' => false],
            'store_credit' => ['enabled' => false, 'is_default' => false],
        ];

        foreach ($configs as $code => $attrs) {
            OutletPaymentMethodConfig::query()
                ->where('outlet_id', $outletId)
                ->where('payment_method_code', $code)
                ->update($attrs);
        }

        PaymentMethod::query()->updateOrCreate(
            ['id' => 'wrwb-cash'],
            ['name' => 'Cash WR WB', 'type' => 'cash', 'integration' => 'cash', 'fee' => 0, 'status' => 'active'],
        );
        PaymentMethod::query()->updateOrCreate(
            ['id' => 'wrwb-qris'],
            ['name' => 'QRIS Static WR WB', 'type' => 'qris', 'integration' => 'manual_qris', 'fee' => 0, 'status' => 'active'],
        );
    }

    private function seedBankAccounts(): void
    {
        $byCode = \App\Models\Modules\Accounting\Domain\Account::query()->pluck('id', 'code');

        BankAccount::query()->updateOrCreate(
            ['id' => 'wrwb-kas'],
            [
                'account_name' => 'Kas WR WB',
                'bank_name' => 'Kas',
                'account_number' => 'KAS-WRWB',
                'chart_account_id' => $byCode->get('1100'),
                'is_default' => false,
            ],
        );
        BankAccount::query()->updateOrCreate(
            ['id' => 'wrwb-bca'],
            [
                'account_name' => 'Bank BCA WR WB',
                'bank_name' => 'BCA',
                'account_number' => '1234567890',
                'chart_account_id' => $byCode->get('1111'),
                'is_default' => true,
            ],
        );
        BankAccount::query()->updateOrCreate(
            ['id' => 'wrwb-qris-clearing'],
            [
                'account_name' => 'QRIS Clearing WR WB',
                'bank_name' => 'QRIS',
                'account_number' => 'QRIS-WRWB',
                'chart_account_id' => $byCode->get('1120'),
                'is_default' => false,
            ],
        );
    }

    private function seedWarehouse(): void
    {
        DB::table('warehouses')->updateOrInsert(
            ['code' => 'WRWB-WH-MAIN'],
            [
                'outlet_id' => CustomerDemoContext::outletId(),
                'name' => 'Gudang Utama WR WB',
                'type' => 'outlet',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        CustomerDemoContext::$warehouseId = (int) DB::table('warehouses')
            ->where('code', 'WRWB-WH-MAIN')
            ->value('id');
    }

    private function seedSupplier(): void
    {
        DB::table('suppliers')->updateOrInsert(
            ['name' => 'PT Supplier WR WB'],
            [
                'status' => 'active',
                'contact' => '021-5550200',
                'email' => 'supplier@wrwb.demo',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        CustomerDemoContext::$supplierId = (int) DB::table('suppliers')
            ->where('name', 'PT Supplier WR WB')
            ->value('id');
    }
}
