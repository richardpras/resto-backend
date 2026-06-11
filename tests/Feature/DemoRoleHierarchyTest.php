<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\Demo\DemoFoundationSeeder;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DemoRoleHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM_PERMISSIONS = [
        'settings.manage',
        'users.view',
        'users.create',
        'roles.view',
        'roles.assign_permissions',
        'permissions.view',
    ];

    private const OWNER_BUSINESS_PERMISSIONS = [
        'dashboard.view_all_outlets',
        'reports.view',
        'accounting.manage',
        'payroll.view',
        'inventory.manage',
        'settings.view',
        'settings.update',
        'finance.reconcile',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seed(UserManagementPermissionsSeeder::class);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(DemoFoundationSeeder::class);
    }

    public function test_demo_admin_role_has_platform_permissions(): void
    {
        $role = Role::query()->where('name', 'Demo Admin')->firstOrFail();
        $codes = $role->permissions()->pluck('code')->all();

        foreach (self::PLATFORM_PERMISSIONS as $code) {
            $this->assertContains($code, $codes, "Demo Admin missing {$code}");
        }
    }

    public function test_demo_owner_role_excludes_platform_permissions(): void
    {
        $role = Role::query()->where('name', 'Demo Owner')->firstOrFail();
        $codes = $role->permissions()->pluck('code')->all();

        foreach (self::PLATFORM_PERMISSIONS as $code) {
            $this->assertNotContains($code, $codes, "Demo Owner must not have {$code}");
        }
    }

    public function test_demo_owner_role_retains_business_permissions(): void
    {
        $role = Role::query()->where('name', 'Demo Owner')->firstOrFail();
        $codes = $role->permissions()->pluck('code')->all();

        foreach (self::OWNER_BUSINESS_PERMISSIONS as $code) {
            $this->assertContains($code, $codes, "Demo Owner missing {$code}");
        }
    }

    public function test_demo_admin_user_exists_with_full_platform_role(): void
    {
        $admin = User::query()->where('email', 'admin@restohub.demo')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->roles()->where('name', 'Demo Admin')->exists());
    }

    public function test_demo_owner_user_has_owner_role_not_admin(): void
    {
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        $this->assertTrue($owner->roles()->where('name', 'Demo Owner')->exists());
        $this->assertFalse($owner->roles()->where('name', 'Demo Admin')->exists());
    }

    public function test_owner_cannot_access_bug_reports_admin_api(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        $owner->outlets()->syncWithoutDetaching([(int) $outlet->id]);
        Passport::actingAs($owner);

        $this->getJson('/api/v1/bug-reports')->assertForbidden();
    }

    public function test_admin_can_access_failed_jobs_summary_api(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $admin = User::query()->where('email', 'admin@restohub.demo')->firstOrFail();
        $admin->outlets()->syncWithoutDetaching([(int) $outlet->id]);
        Passport::actingAs($admin);

        $this->getJson('/api/v1/system/failed-jobs/summary')->assertOk();
    }

    public function test_owner_cannot_access_failed_jobs_summary_api(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        $owner->outlets()->syncWithoutDetaching([(int) $outlet->id]);
        Passport::actingAs($owner);

        $this->getJson('/api/v1/system/failed-jobs/summary')->assertForbidden();
    }

    public function test_owner_can_access_executive_sales_report(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        $owner->outlets()->syncWithoutDetaching([(int) $outlet->id]);
        Passport::actingAs($owner);

        $this->getJson('/api/v1/reports/executive-sales?outletId='.$outlet->id)
            ->assertOk();
    }

    public function test_owner_can_patch_assigned_outlet(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        $owner->outlets()->syncWithoutDetaching([(int) $outlet->id]);
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
