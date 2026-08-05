<?php

namespace Database\Seeders;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuItemOutlet;
use App\Models\Modules\Menu\Domain\MenuRecipe;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReceiptSetting;
use App\Models\Modules\Settings\Domain\PaymentMethod;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\Production\Services\ProductionStationProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Master-only dataset for a single outlet: WR WB.
 *
 * Includes: outlet, payment methods, stations, tables, menu+recipes, ingredient stock,
 * login users (with screen PIN), and HR employees linked to those users.
 * Does NOT seed orders, payments, POS sessions, purchase docs, or other transactions.
 *
 * Prerequisites: permissions + default roles (run DatabaseSeeder prefix, or):
 *   php artisan db:seed --class=UserManagementPermissionsSeeder
 *   php artisan db:seed --class=DefaultRolesPermissionsSeeder
 *
 * Usage:
 *   php artisan db:seed --class=WrWbMasterOnlySeeder
 */
class WrWbMasterOnlySeeder extends Seeder
{
    public const OUTLET_CODE = 'WR-WB';

    public const OUTLET_NAME = 'WR WB';

    public const DOMAIN = 'wrwb.local';

    public const TENANT_ID = 1;

    public function run(): void
    {
        $this->ensureRolesExist();

        DB::transaction(function (): void {
            $outlet = $this->seedOutlet();
            $this->seedPaymentMethods();
            $this->seedStations($outlet);
            $this->seedTables($outlet);
            $this->seedMenuAndInventory($outlet);
            $this->seedUsersAndEmployees($outlet);
        });

        $this->command?->info('WR WB master seeded (no transactions).');
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

    private function seedUsersAndEmployees(Outlet $outlet): void
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
                'position' => 'Super Admin',
                'base_salary' => 15000000,
                'attach_outlet' => true,
            ],
            [
                'name' => 'Owner WR WB',
                'email' => 'owner@'.self::DOMAIN,
                'password' => 'wrwb123',
                'pin' => '1234',
                'role' => 'Owner',
                'employee_no' => 'EMP-WRWB-001',
                'position' => 'Owner',
                'base_salary' => 12000000,
                'attach_outlet' => true,
            ],
            [
                'name' => 'Manager WR WB',
                'email' => 'manager@'.self::DOMAIN,
                'password' => 'wrwb123',
                'pin' => '2345',
                'role' => 'Manager',
                'employee_no' => 'EMP-WRWB-002',
                'position' => 'Manager',
                'base_salary' => 9800000,
                'attach_outlet' => true,
            ],
            [
                'name' => 'Cashier WR WB',
                'email' => 'cashier@'.self::DOMAIN,
                'password' => 'wrwb123',
                'pin' => '3456',
                'role' => 'Cashier',
                'employee_no' => 'EMP-WRWB-003',
                'position' => 'Cashier',
                'base_salary' => 4200000,
                'attach_outlet' => true,
            ],
            [
                'name' => 'Kitchen WR WB',
                'email' => 'kitchen@'.self::DOMAIN,
                'password' => 'wrwb123',
                'pin' => '4567',
                'role' => 'Kitchen',
                'employee_no' => 'EMP-WRWB-004',
                'position' => 'Chef',
                'base_salary' => 8500000,
                'attach_outlet' => true,
            ],
        ];

        foreach ($users as $row) {
            $roleId = $roleIds[$row['role']] ?? null;
            if ($roleId === null) {
                $this->command?->warn("Role [{$row['role']}] missing — skip {$row['email']}");

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

            Employee::query()->updateOrCreate(
                ['employee_no' => $row['employee_no']],
                [
                    'tenant_id' => self::TENANT_ID,
                    'user_id' => $user->id,
                    'outlet_id' => $outlet->id,
                    'full_name' => $row['name'],
                    'email' => $row['email'],
                    'position' => $row['position'],
                    'outlet' => self::OUTLET_NAME,
                    'salary_type' => 'monthly',
                    'base_salary' => $row['base_salary'],
                    'overtime_rate' => 35000,
                    'hire_date' => now()->subMonths(6)->toDateString(),
                    'status' => Employee::STATUS_ACTIVE,
                ],
            );
        }
    }
}
