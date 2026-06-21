<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Production\Domain\ProductionStation;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\TestCase;

class ProductionStationMenuAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use ProductionStationTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seed(UserManagementPermissionsSeeder::class);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_menu_item_create_and_update_infer_production_station_from_category(): void
    {
        $outlet = $this->createProductionStationOutlet();
        $kitchen = $this->createStation($outlet, 'kitchen');
        $bar = $this->createStation($outlet, 'bar');
        $foodCategory = $this->createCategory('Food');
        $beverageCategory = $this->createCategory('Beverage');

        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $created = $this->postJson('/api/v1/menu-items', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'name' => 'Nasi Goreng',
            'menuCategoryId' => $foodCategory->id,
            'emoji' => '🍽️',
            'price' => 45000,
            'available' => true,
            'productionStationId' => $bar->id,
        ])->assertCreated()
            ->assertJsonPath('data.productionStation.id', (int) $kitchen->id)
            ->assertJsonPath('data.productionStation.code', 'kitchen')
            ->json('data');

        $menuItemId = (int) $created['id'];

        $this->putJson('/api/v1/menu-items/'.$menuItemId, [
            'menuCategoryId' => $beverageCategory->id,
        ])->assertOk()
            ->assertJsonPath('data.productionStation.id', (int) $bar->id)
            ->assertJsonPath('data.productionStation.code', 'bar');

        $this->putJson('/api/v1/menu-items/'.$menuItemId, [
            'productionStationId' => null,
        ])->assertOk()
            ->assertJsonPath('data.productionStation.id', (int) $bar->id);

        $this->assertDatabaseHas('menu_items', [
            'id' => $menuItemId,
            'production_station_id' => $bar->id,
        ]);
    }

    public function test_existing_menu_items_without_station_remain_valid(): void
    {
        $outlet = $this->createProductionStationOutlet();
        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Legacy Item',
            'category' => 'Food',
            'emoji' => '🍽️',
            'price' => 10000,
            'available' => true,
        ]);

        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $this->getJson('/api/v1/menu-items/'.$menuItem->id)
            ->assertOk()
            ->assertJsonPath('data.productionStation', null);
    }

    private function createCategory(string $name): MenuCategory
    {
        return MenuCategory::query()->create([
            'tenant_id' => 1,
            'code' => Str::slug(strtolower($name), '_') ?: 'category',
            'name' => $name,
            'name_en' => $name,
            'name_id' => $name,
            'sort_order' => 10,
            'is_active' => true,
        ]);
    }

    private function createStation($outlet, string $code): ProductionStation
    {
        return ProductionStation::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => $code,
            'name' => ucfirst($code),
            'type' => $code,
            'display_order' => 10,
            'is_active' => true,
            'kds_enabled' => true,
            'print_enabled' => true,
        ]);
    }
}
