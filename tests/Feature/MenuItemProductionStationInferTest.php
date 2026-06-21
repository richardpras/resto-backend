<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Production\Domain\ProductionStation;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\TestCase;

class MenuItemProductionStationInferTest extends TestCase
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

    public function test_create_infers_production_station_from_menu_category(): void
    {
        $outlet = $this->createProductionStationOutlet();
        $kitchen = $this->createStation($outlet, 'kitchen');
        $this->createStation($outlet, 'bar');
        $foodCategory = $this->createCategory('Food');

        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $this->postJson('/api/v1/menu-items', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'name' => 'Nasi Goreng',
            'menuCategoryId' => $foodCategory->id,
            'emoji' => '🍽️',
            'price' => 45000,
            'available' => true,
            'productionStationId' => 99999,
        ])->assertCreated()
            ->assertJsonPath('data.productionStation.id', (int) $kitchen->id)
            ->assertJsonPath('data.productionStation.code', 'kitchen');
    }

    public function test_update_re_syncs_station_when_menu_category_changes(): void
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
            'name' => 'Es Teh',
            'menuCategoryId' => $foodCategory->id,
            'emoji' => '🥤',
            'price' => 15000,
            'available' => true,
        ])->assertCreated()
            ->assertJsonPath('data.productionStation.id', (int) $kitchen->id)
            ->json('data');

        $menuItemId = (int) $created['id'];

        $this->putJson('/api/v1/menu-items/'.$menuItemId, [
            'menuCategoryId' => $beverageCategory->id,
        ])->assertOk()
            ->assertJsonPath('data.productionStation.id', (int) $bar->id)
            ->assertJsonPath('data.productionStation.code', 'bar');
    }

    public function test_dessert_category_falls_back_to_kitchen_station_when_dessert_missing(): void
    {
        $outlet = $this->createProductionStationOutlet();
        $kitchen = $this->createStation($outlet, 'kitchen');
        $dessertCategory = $this->createCategory('Dessert');

        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $this->postJson('/api/v1/menu-items', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'name' => 'Pudding',
            'menuCategoryId' => $dessertCategory->id,
            'emoji' => '🍮',
            'price' => 20000,
            'available' => true,
        ])->assertCreated()
            ->assertJsonPath('data.productionStation.id', (int) $kitchen->id);
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
