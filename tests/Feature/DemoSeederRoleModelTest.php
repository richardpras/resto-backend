<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Concerns\DemoSeederTestSetup;
use Tests\TestCase;

class DemoSeederRoleModelTest extends TestCase
{
    use DemoSeederTestSetup;
    use RefreshDatabase;

    private const PLATFORM_PERMISSIONS = [
        'settings.manage',
        'users.view',
        'users.create',
        'roles.view',
        'roles.assign_permissions',
        'permissions.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoSeederEnvironment();
    }

    public function test_admin_and_owner_are_distinct_users(): void
    {
        $admin = User::query()->where('email', 'admin@restohub.demo')->firstOrFail();
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();

        $this->assertNotSame((int) $admin->id, (int) $owner->id);
        $this->assertTrue($admin->roles()->where('name', 'Demo Admin')->exists());
        $this->assertTrue($owner->roles()->where('name', 'Demo Owner')->exists());
        $this->assertFalse($owner->roles()->where('name', 'Demo Admin')->exists());
    }

    public function test_admin_has_platform_permissions(): void
    {
        $codes = Role::query()->where('name', 'Demo Admin')->firstOrFail()
            ->permissions()->pluck('code')->all();

        foreach (self::PLATFORM_PERMISSIONS as $code) {
            $this->assertContains($code, $codes, "Demo Admin missing {$code}");
        }
    }

    public function test_owner_lacks_platform_permissions(): void
    {
        $codes = Role::query()->where('name', 'Demo Owner')->firstOrFail()
            ->permissions()->pluck('code')->all();

        foreach (self::PLATFORM_PERMISSIONS as $code) {
            $this->assertNotContains($code, $codes, "Demo Owner must not have {$code}");
        }
    }

    public function test_admin_can_access_bug_reports_api(): void
    {
        $admin = User::query()->where('email', 'admin@restohub.demo')->firstOrFail();
        Passport::actingAs($admin);

        $this->getJson('/api/v1/bug-reports')->assertOk();
    }

    public function test_owner_cannot_access_bug_reports_api(): void
    {
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        Passport::actingAs($owner);

        $this->getJson('/api/v1/bug-reports')->assertForbidden();
    }

    public function test_owner_lacks_settings_manage_for_platform_admin(): void
    {
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        $codes = $owner->roles()->firstOrFail()->permissions()->pluck('code')->all();

        $this->assertNotContains('settings.manage', $codes);
        $this->assertContains('settings.update', $codes);
    }

    public function test_owner_can_patch_assigned_outlet(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        Passport::actingAs($owner);

        $this->patchJson('/api/v1/outlets/'.$outlet->id, [
            'name' => 'Sunset Cafe Updated',
            'address' => $outlet->address,
            'phone' => $outlet->phone ?? '',
            'manager' => $outlet->manager ?? '',
            'status' => 'active',
        ])->assertOk();
    }
}
