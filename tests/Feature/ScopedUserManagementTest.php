<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\Demo\DemoFoundationSeeder;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ScopedUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seed(UserManagementPermissionsSeeder::class);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(DemoFoundationSeeder::class);
    }

    public function test_owner_can_list_operational_users_but_not_admin(): void
    {
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        Passport::actingAs($owner);

        $emails = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))
            ->pluck('email')
            ->all();

        $this->assertNotContains('admin@restohub.demo', $emails);
        $this->assertNotContains('owner@restohub.demo', $emails);
        $this->assertContains('cashier.morning@sunset.demo.resto.local', $emails);
    }

    public function test_owner_can_list_assignable_roles_only(): void
    {
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        Passport::actingAs($owner);

        $roleNames = collect($this->getJson('/api/v1/roles')->assertOk()->json('data'))
            ->pluck('name')
            ->all();

        $this->assertContains('Demo Cashier', $roleNames);
        $this->assertContains('Demo Manager', $roleNames);
        $this->assertNotContains('Demo Admin', $roleNames);
        $this->assertNotContains('Demo Owner', $roleNames);
        $this->assertNotContains('Demo Auditor', $roleNames);
    }

    public function test_owner_can_create_user_and_assign_staff_role(): void
    {
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        Passport::actingAs($owner);

        $cashierRoleId = (int) Role::query()->where('name', 'Demo Cashier')->value('id');

        $create = $this->postJson('/api/v1/users', [
            'name' => 'Scoped Staff',
            'email' => 'scoped.staff@sunset.demo.resto.local',
            'password' => 'secret123',
        ])->assertCreated();

        $userId = (int) $create->json('data.id');

        $this->postJson("/api/v1/users/{$userId}/roles", [
            'roleIds' => [$cashierRoleId],
        ])->assertOk()
            ->assertJsonPath('data.roles.0.name', 'Demo Cashier');
    }

    public function test_owner_cannot_assign_admin_role(): void
    {
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        $cashier = User::query()->where('email', 'cashier.morning@sunset.demo.resto.local')->firstOrFail();
        $adminRoleId = (int) Role::query()->where('name', 'Demo Admin')->value('id');

        Passport::actingAs($owner);

        $this->postJson("/api/v1/users/{$cashier->id}/roles", [
            'roleIds' => [$adminRoleId],
        ])->assertUnprocessable();
    }

    public function test_owner_cannot_modify_admin_user_roles(): void
    {
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        $admin = User::query()->where('email', 'admin@restohub.demo')->firstOrFail();
        $cashierRoleId = (int) Role::query()->where('name', 'Demo Cashier')->value('id');

        Passport::actingAs($owner);

        $this->postJson("/api/v1/users/{$admin->id}/roles", [
            'roleIds' => [$cashierRoleId],
        ])->assertUnprocessable();
    }

    public function test_owner_cannot_create_or_edit_roles(): void
    {
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        Passport::actingAs($owner);

        $this->postJson('/api/v1/roles', [
            'name' => 'Custom Role',
            'description' => 'Should fail',
        ])->assertForbidden();

        $roleId = (int) Role::query()->where('name', 'Demo Cashier')->value('id');
        $this->postJson("/api/v1/roles/{$roleId}/permissions", [
            'permissionIds' => [],
        ])->assertForbidden();
    }

    public function test_owner_can_read_merchant_settings_but_not_patch(): void
    {
        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        Passport::actingAs($owner);

        $before = $this->getJson('/api/v1/merchant-settings')->assertOk()->json('data');
        $payload = array_merge($before, ['name' => 'Owner Cannot Patch']);

        $this->patchJson('/api/v1/merchant-settings', $payload)->assertForbidden();
    }

    public function test_owner_and_manager_lack_merchant_manage_permission(): void
    {
        $ownerCodes = Role::query()->where('name', 'Demo Owner')->firstOrFail()
            ->permissions()->pluck('code')->all();
        $managerCodes = Role::query()->where('name', 'Demo Manager')->firstOrFail()
            ->permissions()->pluck('code')->all();

        $this->assertNotContains('merchant.manage', $ownerCodes);
        $this->assertNotContains('merchant.manage', $managerCodes);
        $this->assertContains('users.view', $ownerCodes);
        $this->assertContains('users.create', $managerCodes);
    }

    public function test_owner_audit_logs_exclude_privileged_users(): void
    {
        $admin = User::query()->where('email', 'admin@restohub.demo')->firstOrFail();
        Passport::actingAs($admin);

        $cashierRoleId = (int) Role::query()->where('name', 'Demo Cashier')->value('id');
        $adminRoleId = (int) Role::query()->where('name', 'Demo Admin')->value('id');

        $staff = $this->postJson('/api/v1/users', [
            'name' => 'Audit Staff',
            'email' => 'audit.staff@sunset.demo.resto.local',
            'password' => 'secret123',
        ])->assertCreated();
        $staffId = (int) $staff->json('data.id');
        $this->postJson("/api/v1/users/{$staffId}/roles", ['roleIds' => [$cashierRoleId]])->assertOk();
        $this->postJson("/api/v1/users/{$admin->id}/roles", ['roleIds' => [$adminRoleId]])->assertOk();

        $owner = User::query()->where('email', 'owner@restohub.demo')->firstOrFail();
        Passport::actingAs($owner);

        $entityIds = collect($this->getJson('/api/v1/user-management/audit-logs?limit=100')->assertOk()->json('data'))
            ->pluck('entityId')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains($staffId, $entityIds);
        $this->assertNotContains((int) $admin->id, $entityIds);
    }
}
