<?php

namespace Tests\Concerns;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

trait InventoryValuationFixture
{
    protected function seedInventoryValuationPermissions(): void
    {
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    protected function actingAsInventoryUser(?Outlet $outlet = null): User
    {
        $this->seedInventoryValuationPermissions();
        Artisan::call('passport:keys', ['--force' => true]);

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_inventory_valuation__'],
            ['description' => 'Inventory valuation tests'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', [
                'pos.use', 'inventory.manage', 'menu.manage', 'foodcost.view',
                'recipe.view', 'recipe.manage', 'production.view', 'production.manage', 'forecast.view',
                'analytics.view', 'analytics.manage',
                'optimization.view', 'optimization.manage',
                'automation.view', 'automation.manage',
                'forecasting.view', 'forecasting.manage',
                'dashboard.view', 'dashboard.manage',
                'finance.shift_close', 'outlets.view_all',
            ])->pluck('id')
        );

        $user = User::factory()->create([
            'email' => 'inv-val-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);
        if ($outlet !== null) {
            $user->outlets()->sync([(int) $outlet->id]);
        }
        Passport::actingAs($user);

        return $user;
    }

    protected function createValuationOutlet(string $name = 'Valuation Outlet'): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'val-out-'.uniqid(),
        ]);
    }

    protected function createIngredientForOutlet(int $outletId, float $price = 0, float $stock = 0): Ingredient
    {
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Ingredient '.uniqid(),
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => $stock,
            'min' => 0,
            'price' => $price,
        ]);

        if ($stock > 0) {
            DB::table('inventory_stocks')->insert([
                'ingredient_id' => $ingredient->id,
                'outlet_id' => $outletId,
                'stock' => $stock,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ingredient;
    }

    /** @return array{ingredientId:int,menuId:int} */
    protected function seedMenuWithRecipe(int $outletId, int $ingredientId, float $recipeQty = 1.0, float $menuPrice = 10000): array
    {
        $menuId = (int) DB::table('menu_items')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Menu '.uniqid(),
            'price' => $menuPrice,
            'available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('menu_recipes')->insert([
            'menu_item_id' => $menuId,
            'inventory_item_id' => $ingredientId,
            'quantity' => $recipeQty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['ingredientId' => $ingredientId, 'menuId' => $menuId];
    }
}
