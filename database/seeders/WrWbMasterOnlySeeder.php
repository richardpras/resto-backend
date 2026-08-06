<?php

namespace Database\Seeders;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\EmployeeShiftAssignment;
use App\Models\Modules\HR\Domain\LeaveType;
use App\Models\Modules\HR\Domain\OvertimeType;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuItemOutlet;
use App\Models\Modules\Menu\Domain\MenuRecipe;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\BankAccount;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReceiptSetting;
use App\Models\Modules\Settings\Domain\OutletTaxAssignment;
use App\Models\Modules\Settings\Domain\PaymentMethod;
use App\Models\Modules\Settings\Domain\Tax;
use App\Models\Modules\UserManagement\Domain\Department;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Position;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Production\Services\ProductionStationProvisioner;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Master-only dataset for a single outlet: WR WB.
 *
 * Includes: outlet, payment methods, tax (PB1) + outlet assignment, stations, tables,
 * menu+recipes, ingredient stock, COA + posting maps + accounting settings + bank accounts,
 * purchasing masters (warehouse + suppliers), login users linked to HR employees,
 * departments/positions/shifts/salary profiles/leave types, and published rosters
 * (random pattern) from today through ~6 months ahead.
 * Does NOT seed orders, payments, POS sessions, purchase docs, journals, or payroll runs.
 *
 * Prerequisites: permissions + default roles (run DatabaseSeeder prefix, or):
 *   php artisan db:seed --class=UserManagementPermissionsSeeder
 *   php artisan db:seed --class=DefaultRolesPermissionsSeeder
 *
 * Usage:
 *   php artisan db:seed --class=WrWbMasterOnlySeeder
 *
 * Docs: docs/demo/WR-WB-MASTER-ONLY-SEEDER.md
 */
class WrWbMasterOnlySeeder extends Seeder
{
    public const OUTLET_CODE = 'WR-WB';

    public const OUTLET_NAME = 'WR WB';

    public const DOMAIN = 'wrwb.local';

    public const TENANT_ID = 1;

    /** Stable tax id for Settings → Tax + POS apply-tax. */
    public const TAX_ID = 'wrwb-pb1';

    public const WAREHOUSE_CODE = 'WRWB-WH-MAIN';

    public function run(): void
    {
        $this->ensureRolesExist();
        $this->call(EssentialCoaAccountsSeeder::class);
        $this->call(AccountingPostingMappingsSeeder::class);
        $this->call(PaymentBankCoaLinkSeeder::class);

        DB::transaction(function (): void {
            $outlet = $this->seedOutlet();
            $this->seedPaymentMethods();
            $this->seedTax($outlet);
            $this->seedStations($outlet);
            $this->seedTables($outlet);
            $this->seedMenuAndInventory($outlet);
            $this->seedAccountingMasters($outlet);
            $this->seedPurchasingMasters($outlet);
            $this->seedPayrollAndHrMasters($outlet);
        });

        $this->command?->info('WR WB master seeded (no transactional docs).');
        $this->command?->info('Tax: PB1 Service Tax 10% (id='.self::TAX_ID.') assigned to outlet.');
        $this->command?->info('Accounting: COA + posting maps + realtime setting + bank accounts.');
        $this->command?->info('Purchasing: warehouse '.self::WAREHOUSE_CODE.' + suppliers.');
        $this->command?->info('HR: employees↔users, shifts, random rosters ~6 months ahead.');
        $this->command?->info('Login examples: superadmin@wrwb.local / wrwb123  PIN 0000  (super_admin)');
        $this->command?->info('               owner@wrwb.local / wrwb123  PIN 1234');
        $this->command?->info('               manager@wrwb.local / wrwb123  PIN 2345');
        $this->command?->info('               cashier@wrwb.local / wrwb123  PIN 3456');
        $this->command?->info('               kitchen@wrwb.local / wrwb123  PIN 4567');
    }

    private function ensureRolesExist(): void
    {
        $needed = ['Admin', 'Owner', 'Manager', 'Cashier', 'Kitchen'];
        $missing = [];
        foreach ($needed as $name) {
            if (! Role::query()->where('name', $name)->exists()) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            $this->command?->warn('Missing roles ['.implode(', ', $missing).'] — seeding permissions/roles first.');
            $this->call(UserManagementPermissionsSeeder::class);
            $this->call(DefaultRolesPermissionsSeeder::class);
        }

        $this->ensureSuperAdminRole();
    }

    /** Platform bypass role expected by {@see User::isSuperAdmin()} (name must be exactly super_admin). */
    private function ensureSuperAdminRole(): void
    {
        $role = Role::query()->updateOrCreate(
            ['name' => 'super_admin'],
            [
                'description' => 'Platform super admin (outlet-scope bypass)',
                'staff_assignable' => false,
                'hierarchy_rank' => 100,
            ],
        );
        $role->permissions()->sync(Permission::query()->pluck('id')->all());
    }

    private function seedOutlet(): Outlet
    {
        $outlet = Outlet::query()->updateOrCreate(
            ['code' => self::OUTLET_CODE],
            [
                'name' => self::OUTLET_NAME,
                'address' => 'Jl. WR WB No. 1',
                'phone' => '0215550199',
                'manager' => 'Manager WR WB',
                'status' => 'active',
                'invoice_prefix' => 'INV-WRWB',
                'order_prefix' => 'ORD-WRWB',
            ],
        );

        OutletReceiptSetting::query()->updateOrCreate(
            ['outlet_id' => $outlet->id],
            [
                'receipt_header' => self::OUTLET_NAME,
                'receipt_footer' => 'Terima kasih - WR WB',
                'show_logo' => true,
                'show_tax_breakdown' => true,
            ],
        );

        return $outlet;
    }

    private function seedPaymentMethods(): void
    {
        foreach (
            [
                ['id' => 'wrwb_cash', 'name' => 'Cash WR WB', 'integration' => 'cash'],
                ['id' => 'wrwb_qris', 'name' => 'QRIS WR WB', 'integration' => 'qris'],
                ['id' => 'wrwb_transfer', 'name' => 'Transfer WR WB', 'integration' => 'transfer'],
            ] as $row
        ) {
            PaymentMethod::query()->updateOrCreate(
                ['id' => $row['id']],
                [
                    'name' => $row['name'],
                    'type' => 'custom',
                    'integration' => $row['integration'],
                    'fee' => 0,
                    'status' => 'active',
                ],
            );
        }
    }

    private function seedTax(Outlet $outlet): void
    {
        Tax::query()->updateOrCreate(
            ['id' => self::TAX_ID],
            [
                'name' => 'PB1 Service Tax',
                'type' => 'percentage',
                'value' => 10,
                'apply_dine_in' => true,
                'apply_takeaway' => true,
                'inclusive' => false,
                'status' => 'active',
                'effective_from' => null,
                'effective_to' => null,
            ],
        );

        OutletTaxAssignment::query()->updateOrCreate(
            [
                'outlet_id' => $outlet->id,
                'tax_id' => self::TAX_ID,
            ],
            [],
        );
    }

    private function seedStations(Outlet $outlet): void
    {
        app(ProductionStationProvisioner::class)->ensureForOutlet(
            $outlet,
            ['kitchen', 'bar', 'cashier', 'dessert'],
            self::TENANT_ID,
        );
    }

    private function seedTables(Outlet $outlet): void
    {
        $tables = [
            ['code' => 'W1', 'name' => 'Table W1', 'zone' => 'Main Hall', 'capacity' => 4],
            ['code' => 'W2', 'name' => 'Table W2', 'zone' => 'Main Hall', 'capacity' => 4],
            ['code' => 'W3', 'name' => 'Table W3', 'zone' => 'Main Hall', 'capacity' => 2],
            ['code' => 'T1', 'name' => 'Table T1', 'zone' => 'Terrace', 'capacity' => 4],
            ['code' => 'VIP1', 'name' => 'Table VIP1', 'zone' => 'VIP', 'capacity' => 8],
        ];

        foreach ($tables as $table) {
            RestaurantTable::query()->updateOrCreate(
                ['outlet_id' => $outlet->id, 'code' => $table['code']],
                [
                    'name' => $table['name'],
                    'capacity' => $table['capacity'],
                    'zone' => $table['zone'],
                    'status' => 'active',
                    'active' => true,
                    'qr_enabled' => false,
                    'qr_version' => 1,
                ],
            );
        }
    }

    private function seedMenuAndInventory(Outlet $outlet): void
    {
        $ingredientStock = [
            'Rice' => [220, 80, 'kg'],
            'Egg' => [120, 30, 'pcs'],
            'Chicken' => [95, 20, 'kg'],
            'Sambal' => [18, 6, 'kg'],
            'Tea Leaves' => [6, 2, 'kg'],
            'Sugar' => [45, 12, 'kg'],
            'Ice Cube' => [90, 30, 'kg'],
            'Coffee Beans' => [12, 3, 'kg'],
            'Milk' => [34, 8, 'l'],
            'Flour' => [26, 7, 'kg'],
            'Lime' => [20, 5, 'kg'],
        ];

        $ingredientIds = [];
        foreach ($ingredientStock as $name => [$stock, $min, $unit]) {
            $ingredient = Ingredient::query()->updateOrCreate(
                ['outlet_id' => $outlet->id, 'name' => $name],
                [
                    'tenant_id' => self::TENANT_ID,
                    'type' => 'ingredient',
                    'unit' => $unit,
                    'stock' => $stock,
                    'min' => $min,
                    'price' => $min * 1000,
                    'notes' => 'WR WB master stock',
                ],
            );
            $ingredientIds[$name] = $ingredient->id;
            InventoryStock::query()->updateOrCreate(
                ['ingredient_id' => $ingredient->id, 'outlet_id' => $outlet->id],
                ['stock' => $stock],
            );
        }

        $stationIdsByCode = ProductionStation::query()
            ->where('outlet_id', $outlet->id)
            ->pluck('id', 'code');

        $menuBlueprint = [
            ['sku' => 'FD001', 'name' => 'Nasi Goreng Nusantara', 'category' => 'Food', 'price' => 45000, 'station' => 'kitchen', 'ingredients' => ['Rice' => 0.3, 'Egg' => 1, 'Chicken' => 0.12, 'Sambal' => 0.03]],
            ['sku' => 'FD002', 'name' => 'Mie Goreng', 'category' => 'Food', 'price' => 42000, 'station' => 'kitchen', 'ingredients' => ['Rice' => 0.2, 'Egg' => 1, 'Chicken' => 0.1, 'Sambal' => 0.03]],
            ['sku' => 'FD003', 'name' => 'Ayam Bakar', 'category' => 'Food', 'price' => 52000, 'station' => 'kitchen', 'ingredients' => ['Chicken' => 0.2, 'Rice' => 0.25]],
            ['sku' => 'BV001', 'name' => 'Es Teh Manis', 'category' => 'Beverage', 'price' => 15000, 'station' => 'bar', 'ingredients' => ['Tea Leaves' => 0.01, 'Sugar' => 0.02, 'Ice Cube' => 0.15]],
            ['sku' => 'BV002', 'name' => 'Es Jeruk', 'category' => 'Beverage', 'price' => 18000, 'station' => 'bar', 'ingredients' => ['Lime' => 0.08, 'Sugar' => 0.02, 'Ice Cube' => 0.15]],
            ['sku' => 'CF001', 'name' => 'Cappuccino', 'category' => 'Beverage', 'price' => 32000, 'station' => 'bar', 'ingredients' => ['Coffee Beans' => 0.018, 'Milk' => 0.12, 'Sugar' => 0.01]],
            ['sku' => 'DS001', 'name' => 'Pisang Goreng', 'category' => 'Dessert', 'price' => 28000, 'station' => 'dessert', 'ingredients' => ['Flour' => 0.1, 'Sugar' => 0.04]],
            ['sku' => 'CS003', 'name' => 'Air Mineral Botol', 'category' => 'Retail', 'price' => 8000, 'station' => 'cashier', 'ingredients' => []],
        ];

        $categoryMap = $this->seedMenuCategories(
            collect($menuBlueprint)->pluck('category')->unique()->values()->all()
        );

        foreach ($menuBlueprint as $row) {
            $stationCode = strtolower((string) $row['station']);
            $productionStationId = $stationIdsByCode->get($stationCode)
                ?? $stationIdsByCode->get('kitchen');

            $menuItem = MenuItem::query()->updateOrCreate(
                ['outlet_id' => $outlet->id, 'name' => $row['name']],
                [
                    'tenant_id' => self::TENANT_ID,
                    'category' => $row['category'],
                    'menu_category_id' => $categoryMap[$row['category']] ?? null,
                    'production_station_id' => $productionStationId,
                    'emoji' => '🍽️',
                    'price' => $row['price'],
                    'available' => true,
                ],
            );

            MenuItemOutlet::query()->updateOrCreate(
                ['menu_item_id' => $menuItem->id, 'outlet_id' => (string) $outlet->id],
                [
                    'is_active' => true,
                    'price_override' => $row['price'],
                    'name_override' => null,
                    'receipt_name' => "{$row['sku']} {$row['name']}",
                ],
            );

            foreach ($row['ingredients'] as $ingredientName => $qty) {
                if (! isset($ingredientIds[$ingredientName])) {
                    continue;
                }
                MenuRecipe::query()->updateOrCreate(
                    [
                        'menu_item_id' => $menuItem->id,
                        'inventory_item_id' => $ingredientIds[$ingredientName],
                    ],
                    ['quantity' => $qty],
                );
            }
        }
    }

    /**
     * @param  list<string>  $categoryNames
     * @return array<string, int> category name -> menu_categories.id
     */
    private function seedMenuCategories(array $categoryNames): array
    {
        $labels = [
            'Food' => ['en' => 'Food', 'id' => 'Makanan'],
            'Beverage' => ['en' => 'Beverage', 'id' => 'Minuman'],
            'Dessert' => ['en' => 'Dessert', 'id' => 'Dessert'],
            'Retail' => ['en' => 'Retail', 'id' => 'Retail'],
        ];

        $map = [];
        $sort = 0;
        foreach ($categoryNames as $name) {
            if (! is_string($name) || trim($name) === '') {
                continue;
            }
            $sort++;
            $code = 'WRWB-'.strtoupper(Str::slug($name, '_'));
            $label = $labels[$name] ?? ['en' => $name, 'id' => $name];
            $category = MenuCategory::query()->updateOrCreate(
                ['code' => $code],
                [
                    'tenant_id' => self::TENANT_ID,
                    'name' => $name,
                    'name_en' => $label['en'],
                    'name_id' => $label['id'],
                    'is_active' => true,
                    'sort_order' => $sort,
                ],
            );
            $map[$name] = (int) $category->id;
        }

        return $map;
    }

    private function seedAccountingMasters(Outlet $outlet): void
    {
        AccountingSetting::query()->updateOrCreate(
            ['tenant_id' => null, 'outlet_id' => $outlet->id],
            ['revenue_posting_mode' => AccountingSetting::MODE_REALTIME],
        );

        $byCode = Account::query()->pluck('id', 'code');

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

    private function seedPurchasingMasters(Outlet $outlet): void
    {
        DB::table('warehouses')->updateOrInsert(
            ['code' => self::WAREHOUSE_CODE],
            [
                'outlet_id' => $outlet->id,
                'name' => 'Gudang Utama WR WB',
                'type' => 'outlet',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Supplier::query()->updateOrCreate(
            ['name' => 'PT Supplier WR WB'],
            [
                'status' => 'active',
                'contact' => '021-5550200',
                'email' => 'supplier@'.self::DOMAIN,
                'address' => 'Jl. Supplier No. 10, Jakarta',
                'payment_term_days' => 14,
                'lead_time_days' => 3,
                'is_active' => true,
            ],
        );

        Supplier::query()->updateOrCreate(
            ['name' => 'CV Bahan Segar WR WB'],
            [
                'status' => 'active',
                'contact' => '081234567890',
                'email' => 'segar@'.self::DOMAIN,
                'address' => 'Pasar Induk, Jakarta',
                'payment_term_days' => 7,
                'lead_time_days' => 1,
                'is_active' => true,
            ],
        );
    }

    private function seedPayrollAndHrMasters(Outlet $outlet): void
    {
        $outletId = (int) $outlet->id;
        [$deptOps, $deptKitchen] = $this->seedDepartments($outletId);
        $positions = $this->seedPositions($outletId, $deptOps, $deptKitchen);
        [$morning, $evening] = $this->seedShifts();
        $this->seedLeaveAndOvertimeTypes($outletId);
        $employees = $this->seedUsersAndEmployees($outlet, $positions);
        $this->seedSchedulesSixMonths($outletId, $employees, $morning, $evening);
    }

    /** @return array{0: Department, 1: Department} */
    private function seedDepartments(int $outletId): array
    {
        $ops = Department::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'code' => 'OPS'],
            ['name' => 'Operasional', 'is_active' => true],
        );
        $kitchen = Department::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'code' => 'DAPUR'],
            ['name' => 'Dapur', 'is_active' => true],
        );

        return [$ops, $kitchen];
    }

    /**
     * @return array<string, Position>
     */
    private function seedPositions(int $outletId, Department $ops, Department $kitchen): array
    {
        $specs = [
            'super_admin' => ['name' => 'Super Admin', 'dept' => $ops, 'code' => 'SUPER_ADMIN'],
            'owner' => ['name' => 'Owner', 'dept' => $ops, 'code' => 'OWNER'],
            'manager' => ['name' => 'Manager', 'dept' => $ops, 'code' => 'MANAGER'],
            'cashier' => ['name' => 'Cashier', 'dept' => $ops, 'code' => 'CASHIER'],
            'chef' => ['name' => 'Chef', 'dept' => $kitchen, 'code' => 'CHEF'],
        ];

        $positions = [];
        $sort = 0;
        foreach ($specs as $key => $row) {
            $sort++;
            $positions[$key] = Position::query()->updateOrCreate(
                ['outlet_id' => $outletId, 'code' => $row['code']],
                [
                    'name' => $row['name'],
                    'department_id' => $row['dept']->id,
                    'is_active' => true,
                    'sort_order' => $sort,
                ],
            );
        }

        return $positions;
    }

    /** @return array{0: Shift, 1: Shift} */
    private function seedShifts(): array
    {
        $morning = Shift::query()->updateOrCreate(
            ['code' => 'WRWB-PAGI'],
            [
                'tenant_id' => self::TENANT_ID,
                'name' => 'Shift Pagi',
                'start_time' => '07:00:00',
                'end_time' => '15:00:00',
                'active' => true,
                'late_tolerance_minutes' => 15,
                'overtime_after_minutes' => 30,
            ],
        );
        $evening = Shift::query()->updateOrCreate(
            ['code' => 'WRWB-SORE'],
            [
                'tenant_id' => self::TENANT_ID,
                'name' => 'Shift Sore',
                'start_time' => '15:00:00',
                'end_time' => '23:00:00',
                'active' => true,
                'late_tolerance_minutes' => 15,
                'overtime_after_minutes' => 30,
            ],
        );

        return [$morning, $evening];
    }

    private function seedLeaveAndOvertimeTypes(int $outletId): void
    {
        LeaveType::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'code' => 'CUTI-THN'],
            ['name' => 'Cuti Tahunan', 'paid_leave' => true, 'is_active' => true],
        );
        LeaveType::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'code' => 'SAKIT'],
            ['name' => 'Sakit', 'paid_leave' => false, 'is_active' => true],
        );
        OvertimeType::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'code' => 'OT-REG'],
            ['name' => 'Lembur Reguler', 'multiplier' => 1.5, 'is_active' => true],
        );
    }

    /**
     * @param  array<string, Position>  $positions
     * @return list<array{employee: Employee, shift_key: string}>
     */
    private function seedUsersAndEmployees(Outlet $outlet, array $positions): array
    {
        $roleIds = Role::query()
            ->whereIn('name', ['super_admin', 'Admin', 'Owner', 'Manager', 'Cashier', 'Kitchen'])
            ->pluck('id', 'name');

        $users = [
            [
                'name' => 'Super Admin WR WB',
                'email' => 'superadmin@'.self::DOMAIN,
                'password' => 'wrwb123',
                'pin' => '0000',
                'role' => 'super_admin',
                'employee_no' => 'EMP-WRWB-000',
                'position_key' => 'super_admin',
                'base_salary' => 15000000,
                'attach_outlet' => true,
                'shift_key' => 'morning',
                'schedule' => false,
            ],
            [
                'name' => 'Owner WR WB',
                'email' => 'owner@'.self::DOMAIN,
                'password' => 'wrwb123',
                'pin' => '1234',
                'role' => 'Owner',
                'employee_no' => 'EMP-WRWB-001',
                'position_key' => 'owner',
                'base_salary' => 12000000,
                'attach_outlet' => true,
                'shift_key' => 'morning',
                'schedule' => false,
            ],
            [
                'name' => 'Manager WR WB',
                'email' => 'manager@'.self::DOMAIN,
                'password' => 'wrwb123',
                'pin' => '2345',
                'role' => 'Manager',
                'employee_no' => 'EMP-WRWB-002',
                'position_key' => 'manager',
                'base_salary' => 9800000,
                'attach_outlet' => true,
                'shift_key' => 'morning',
                'schedule' => true,
            ],
            [
                'name' => 'Cashier WR WB',
                'email' => 'cashier@'.self::DOMAIN,
                'password' => 'wrwb123',
                'pin' => '3456',
                'role' => 'Cashier',
                'employee_no' => 'EMP-WRWB-003',
                'position_key' => 'cashier',
                'base_salary' => 4200000,
                'attach_outlet' => true,
                'shift_key' => 'evening',
                'schedule' => true,
            ],
            [
                'name' => 'Kitchen WR WB',
                'email' => 'kitchen@'.self::DOMAIN,
                'password' => 'wrwb123',
                'pin' => '4567',
                'role' => 'Kitchen',
                'employee_no' => 'EMP-WRWB-004',
                'position_key' => 'chef',
                'base_salary' => 8500000,
                'attach_outlet' => true,
                'shift_key' => 'morning',
                'schedule' => true,
            ],
        ];

        $scheduled = [];

        foreach ($users as $row) {
            $roleId = $roleIds[$row['role']] ?? null;
            if ($roleId === null) {
                $this->command?->warn("Role [{$row['role']}] missing — skip {$row['email']}");

                continue;
            }

            $position = $positions[$row['position_key']] ?? null;
            if ($position === null) {
                $this->command?->warn("Position [{$row['position_key']}] missing — skip {$row['email']}");

                continue;
            }

            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'password' => $row['password'],
                    'pin_hash' => $row['pin'],
                ],
            );
            $user->roles()->sync([(int) $roleId]);
            if ($row['attach_outlet']) {
                $user->outlets()->syncWithoutDetaching([(int) $outlet->id]);
            }

            $employee = Employee::query()->updateOrCreate(
                ['employee_no' => $row['employee_no']],
                [
                    'tenant_id' => self::TENANT_ID,
                    'user_id' => $user->id,
                    'outlet_id' => $outlet->id,
                    'full_name' => $row['name'],
                    'email' => $row['email'],
                    'position' => $position->name,
                    'position_id' => $position->id,
                    'department_id' => $position->department_id,
                    'outlet' => self::OUTLET_NAME,
                    'salary_type' => 'monthly',
                    'base_salary' => $row['base_salary'],
                    'overtime_rate' => 35000,
                    'hire_date' => now()->subMonths(6)->toDateString(),
                    'status' => Employee::STATUS_ACTIVE,
                ],
            );

            EmployeeSalaryProfile::query()->updateOrCreate(
                ['employee_id' => $employee->id],
                [
                    'basic_salary' => $row['base_salary'],
                    'default_allowance' => 300000,
                    'default_deduction' => 100000,
                    'overtime_rate_type' => EmployeeSalaryProfile::OVERTIME_RATE_FIXED_HOURLY,
                    'overtime_rate_value' => 35000,
                ],
            );

            if ($row['schedule']) {
                $scheduled[] = [
                    'employee' => $employee,
                    'shift_key' => $row['shift_key'],
                ];
            }
        }

        return $scheduled;
    }

    /**
     * @param  list<array{employee: Employee, shift_key: string}>  $scheduled
     */
    private function seedSchedulesSixMonths(int $outletId, array $scheduled, Shift $morning, Shift $evening): void
    {
        if ($scheduled === []) {
            return;
        }

        $from = Carbon::now()->startOfDay();
        $to = Carbon::now()->addMonths(6)->startOfDay();

        foreach ($scheduled as $row) {
            /** @var Employee $employee */
            $employee = $row['employee'];
            $preferred = $row['shift_key'] === 'evening' ? $evening : $morning;
            $alt = $preferred->id === $morning->id ? $evening : $morning;

            EmployeeShiftAssignment::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'shift_id' => $preferred->id],
                [
                    'outlet_id' => $outletId,
                    'effective_from' => $from->toDateString(),
                    'effective_until' => $to->toDateString(),
                    'is_active' => true,
                    'notes' => 'WR WB master seed assignment',
                ],
            );

            // Deterministic “random” per employee so re-seed is stable.
            $seed = crc32((string) $employee->employee_no);
            mt_srand($seed);

            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $isOff = $d->isSunday() || (mt_rand(1, 100) <= 8);
                if ($isOff) {
                    EmployeeRoster::query()
                        ->where('employee_id', $employee->id)
                        ->whereDate('roster_date', $d->toDateString())
                        ->delete();

                    continue;
                }

                $shiftId = mt_rand(1, 100) <= 75 ? $preferred->id : $alt->id;

                EmployeeRoster::query()->updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'roster_date' => $d->toDateString(),
                    ],
                    [
                        'outlet_id' => $outletId,
                        'shift_id' => $shiftId,
                        'status' => EmployeeRoster::STATUS_PUBLISHED,
                        'published_at' => now(),
                        'notes' => null,
                    ],
                );
            }
        }

        mt_srand();
    }
}
