<?php

namespace Tests\Concerns;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

trait PaymentHealthTestFixture
{
    protected function actingAsSettingsManager(): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_payment_health_settings__'],
            ['description' => 'Settings manager for payment health tests'],
        );
        $permission = Permission::query()->where('code', 'settings.manage')->firstOrFail();
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    protected function createPaymentHealthOutlet(string $prefix = 'PH'): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet '.Str::upper(Str::random(4)),
            'code' => $prefix.'-'.Str::upper(Str::random(6)),
            'status' => 'active',
        ]);
    }
}
