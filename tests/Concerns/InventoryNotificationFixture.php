<?php

namespace Tests\Concerns;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\Inventory\Events\InventoryCriticalAlertRaised;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

trait InventoryNotificationFixture
{
    protected function createInventoryOutlet(string $prefix = 'INV'): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet',
            'code' => $prefix.'-'.Str::upper(Str::random(6)),
            'status' => 'active',
        ]);
    }

    protected function createUserWithPermission(string $permissionCode, Outlet $outlet): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        Artisan::call('passport:keys', ['--force' => true]);

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_'.$permissionCode.'__'],
            ['description' => 'Test '.$permissionCode],
        );
        $permission = Permission::query()->where('code', $permissionCode)->firstOrFail();
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        $user->outlets()->sync([(int) $outlet->id]);

        return $user;
    }

    protected function dispatchCriticalAlert(
        Outlet $outlet,
        int $ingredientId,
        string $ingredientName,
        float $currentStock,
        float $minimumStock,
    ): InventoryCriticalAlertRaised {
        $event = new InventoryCriticalAlertRaised(
            outletId: (int) $outlet->id,
            ingredientId: $ingredientId,
            ingredientName: $ingredientName,
            currentStock: $currentStock,
            minimumStock: $minimumStock,
        );

        event($event);

        return $event;
    }
}
