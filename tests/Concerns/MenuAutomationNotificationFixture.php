<?php

namespace Tests\Concerns;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\Menu\Services\NotificationDispatchService;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

trait MenuAutomationNotificationFixture
{
    protected function createMenuAutomationOutlet(string $prefix = 'MENU'): Outlet
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
            ['name' => '__test_menu_'.$permissionCode.'__'],
            ['description' => 'Test '.$permissionCode],
        );
        $permission = Permission::query()->where('code', $permissionCode)->firstOrFail();
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        $user->outlets()->sync([(int) $outlet->id]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function dispatchMenuAutomationAlert(Outlet $outlet, array $overrides = []): AutomationAlert
    {
        $alert = AutomationAlert::query()->create(array_merge([
            'outlet_id' => $outlet->id,
            'alert_type' => 'food_cost',
            'severity' => 'warning',
            'title' => 'Food cost exceeds threshold',
            'description' => 'Average food cost 45.00% exceeds threshold 40.00%.',
            'payload_json' => [
                'averageFoodCostPercent' => 45.0,
                'threshold' => 40.0,
            ],
            'status' => AutomationAlert::STATUS_OPEN,
            'triggered_at' => now(),
        ], $overrides));

        app(NotificationDispatchService::class)->dispatch($alert, ['database']);

        return $alert;
    }
}
