<?php

namespace Tests\Concerns;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Laravel\Passport\Passport;

trait HrmApiFixture
{
    protected ?User $hrmApiUser = null;

    protected function seedHrmPermissions(): void
    {
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    /**
     * Act as a user with full HRM API permissions (employees, attendance, payroll, loans, overtime).
     */
    protected function actingAsHrmApiAdministrator(): User
    {
        $this->seedHrmPermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_hrm_api_admin__'],
            ['description' => 'Test fixture: full HRM API access'],
        );

        $role->permissions()->sync(Permission::query()->pluck('id')->all());

        $user = User::factory()->create([
            'email' => 'hrm-api-fixture-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        $this->hrmApiUser = $user;

        Passport::actingAs($user);

        return $user;
    }

    protected function grantHrmApiUserOutletAccess(int $outletId): void
    {
        $this->hrmApiUser?->outlets()->syncWithoutDetaching([(int) $outletId]);
    }
}
