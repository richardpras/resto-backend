<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\User;
use Database\Seeders\DefaultRolesPermissionsSeeder;
use Database\Seeders\UserManagementPermissionsSeeder;
use Database\Seeders\WrWbMasterOnlySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WrWbMasterOnlySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_wr_wb_master_without_orders(): void
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        $this->seed(DefaultRolesPermissionsSeeder::class);
        $this->seed(WrWbMasterOnlySeeder::class);

        $outlet = Outlet::query()->where('code', WrWbMasterOnlySeeder::OUTLET_CODE)->first();
        $this->assertNotNull($outlet);
        $this->assertSame(WrWbMasterOnlySeeder::OUTLET_NAME, $outlet->name);

        $this->assertTrue(User::query()->where('email', 'cashier@wrwb.local')->exists());
        $this->assertTrue(User::query()->where('email', 'superadmin@wrwb.local')->exists());
        $super = User::query()->where('email', 'superadmin@wrwb.local')->first();
        $this->assertNotNull($super);
        $this->assertTrue($super->isSuperAdmin());
        $this->assertTrue(Employee::query()->where('employee_no', 'EMP-WRWB-003')->exists());
        $this->assertGreaterThan(0, MenuItem::query()->where('outlet_id', $outlet->id)->count());
        $this->assertGreaterThan(0, MenuCategory::query()->where('code', 'like', 'WRWB-%')->count());
        $this->assertSame(
            0,
            MenuItem::query()->where('outlet_id', $outlet->id)->whereNull('menu_category_id')->count(),
        );
        $this->assertGreaterThan(0, Ingredient::query()->where('outlet_id', $outlet->id)->count());
        $this->assertGreaterThan(0, RestaurantTable::query()->where('outlet_id', $outlet->id)->count());
        $this->assertSame(0, Order::query()->count());
    }
}
