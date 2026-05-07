<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OutletSettingsAccessTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture {
        assignUserToOutlets as protected fixtureAssignUserToOutlets;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_non_owner_only_sees_assigned_outlets(): void
    {
        $this->seedUserManagementGatePermissions();

        $role = Role::query()->create([
            'name' => '__test_outlet_settings_viewer__',
            'description' => 'Test fixture: settings view only',
        ]);

        $permissionId = Permission::query()
            ->where('code', 'settings.view')
            ->value('id');
        self::assertNotNull($permissionId);
        $role->permissions()->sync([(int) $permissionId]);

        $user = User::factory()->create([
            'email' => 'outlet-scope-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        $assignedOutlet = Outlet::query()->create([
            'code' => 'out-assigned-'.uniqid(),
            'name' => 'Assigned Outlet',
            'address' => 'Addr A',
            'phone' => '0800',
            'manager' => 'A',
            'status' => 'active',
        ]);
        Outlet::query()->create([
            'code' => 'out-unassigned-'.uniqid(),
            'name' => 'Unassigned Outlet',
            'address' => 'Addr B',
            'phone' => '0801',
            'manager' => 'B',
            'status' => 'active',
        ]);

        $this->fixtureAssignUserToOutlets($user, [$assignedOutlet->id]);
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/outlets')->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Success');
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $assignedOutlet->id);
    }

    public function test_user_can_be_assigned_to_multiple_outlets(): void
    {
        $user = User::factory()->create([
            'email' => 'multi-outlet-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);

        $firstOutlet = Outlet::query()->create([
            'code' => 'out-multi-a-'.uniqid(),
            'name' => 'Multi Outlet A',
            'address' => 'Addr A',
            'phone' => '0900',
            'manager' => 'Manager A',
            'status' => 'active',
        ]);
        $secondOutlet = Outlet::query()->create([
            'code' => 'out-multi-b-'.uniqid(),
            'name' => 'Multi Outlet B',
            'address' => 'Addr B',
            'phone' => '0901',
            'manager' => 'Manager B',
            'status' => 'active',
        ]);

        $this->fixtureAssignUserToOutlets($user, [$firstOutlet->id, $secondOutlet->id]);

        $this->assertCount(2, $user->outlets()->pluck('outlets.id'));
    }

    public function test_owner_permission_receives_all_outlet_ids(): void
    {
        $this->seedUserManagementGatePermissions();

        $permission = Permission::query()->firstOrCreate(
            ['code' => 'outlets.view_all'],
            ['name' => 'View all outlets'],
        );

        $role = Role::query()->create([
            'name' => '__test_outlet_view_all__',
            'description' => 'Test fixture: all outlets access',
        ]);
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create([
            'email' => 'outlet-owner-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        $activeA = Outlet::query()->create([
            'code' => 'out-active-a-'.uniqid(),
            'name' => 'Active A',
            'address' => 'Addr A',
            'phone' => '0810',
            'manager' => 'A',
            'status' => 'active',
        ]);
        $activeB = Outlet::query()->create([
            'code' => 'out-active-b-'.uniqid(),
            'name' => 'Active B',
            'address' => 'Addr B',
            'phone' => '0811',
            'manager' => 'B',
            'status' => 'active',
        ]);
        Outlet::query()->create([
            'code' => 'out-inactive-'.uniqid(),
            'name' => 'Inactive Outlet',
            'address' => 'Addr C',
            'phone' => '0812',
            'manager' => 'C',
            'status' => 'inactive',
        ]);

        $resolver = app(OutletAccessResolver::class);
        $allowedIds = $resolver->allowedOutletIds($user);

        sort($allowedIds);
        $expected = [$activeA->id, $activeB->id];
        sort($expected);

        $this->assertSame($expected, $allowedIds);
    }

    public function test_auth_me_includes_scoped_outlets_payload(): void
    {
        $this->seedUserManagementGatePermissions();

        $role = Role::query()->create([
            'name' => '__test_outlet_auth_me_scope__',
            'description' => 'Test fixture: scoped auth me outlets',
        ]);

        $settingsViewPermissionId = Permission::query()
            ->where('code', 'settings.view')
            ->value('id');
        self::assertNotNull($settingsViewPermissionId);
        $role->permissions()->sync([(int) $settingsViewPermissionId]);

        $user = User::factory()->create([
            'email' => 'auth-me-outlets-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        $assignedOutlet = Outlet::query()->create([
            'code' => 'out-auth-assigned-'.uniqid(),
            'name' => 'Assigned Outlet',
            'address' => 'Addr A',
            'phone' => '0820',
            'manager' => 'A',
            'status' => 'active',
        ]);
        Outlet::query()->create([
            'code' => 'out-auth-unassigned-'.uniqid(),
            'name' => 'Unassigned Outlet',
            'address' => 'Addr B',
            'phone' => '0821',
            'manager' => 'B',
            'status' => 'active',
        ]);

        $this->fixtureAssignUserToOutlets($user, [$assignedOutlet->id]);
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/auth/me')->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'email',
                'pinSet',
                'roles',
                'permissionCodes',
                'outlets',
            ],
        ]);
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.name', $user->name);
        $response->assertJsonPath('data.email', $user->email);
        $response->assertJsonCount(1, 'data.outlets');
        $response->assertJsonPath('data.outlets.0.id', $assignedOutlet->id);
        $response->assertJsonPath('data.outlets.0.code', $assignedOutlet->code);
        $response->assertJsonPath('data.outlets.0.name', $assignedOutlet->name);
    }

    public function test_non_owner_cannot_read_unassigned_outlet_from_list(): void
    {
        [$assignedOutlet, $unassignedOutlet] = $this->actingAsScopedSettingsUser();

        $response = $this->getJson('/api/v1/outlets')->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $assignedOutlet->id);
        $response->assertJsonMissing(['id' => $unassignedOutlet->id]);
    }

    public function test_non_owner_cannot_update_unassigned_outlet(): void
    {
        [$assignedOutlet, $unassignedOutlet] = $this->actingAsScopedSettingsUser();

        $this->patchJson('/api/v1/outlets/'.$unassignedOutlet->id, [
            'code' => $unassignedOutlet->code,
            'name' => 'Updated Outside Scope',
            'address' => $unassignedOutlet->address,
            'phone' => $unassignedOutlet->phone,
            'manager' => $unassignedOutlet->manager,
            'status' => $unassignedOutlet->status,
            'invoicePrefix' => null,
            'orderPrefix' => null,
        ])->assertNotFound();

        $this->patchJson('/api/v1/outlets/'.$assignedOutlet->id, [
            'code' => $assignedOutlet->code,
            'name' => 'Updated In Scope',
            'address' => $assignedOutlet->address,
            'phone' => $assignedOutlet->phone,
            'manager' => $assignedOutlet->manager,
            'status' => $assignedOutlet->status,
            'invoicePrefix' => null,
            'orderPrefix' => null,
        ])->assertOk()
            ->assertJsonPath('data.id', $assignedOutlet->id);
    }

    public function test_non_owner_cannot_delete_unassigned_outlet(): void
    {
        [$assignedOutlet, $unassignedOutlet] = $this->actingAsScopedSettingsUser();

        $this->deleteJson('/api/v1/outlets/'.$unassignedOutlet->id)->assertNotFound();
        $this->assertDatabaseHas('outlets', ['id' => $unassignedOutlet->id]);

        $this->deleteJson('/api/v1/outlets/'.$assignedOutlet->id)->assertOk();
        $this->assertDatabaseMissing('outlets', ['id' => $assignedOutlet->id]);
    }

    public function test_non_owner_cannot_delete_unassigned_outlet_even_with_valid_token(): void
    {
        [, $unassignedOutlet] = $this->actingAsScopedSettingsUser();

        $this->deleteJson('/api/v1/outlets/'.$unassignedOutlet->id)->assertNotFound();
        $this->assertDatabaseHas('outlets', ['id' => $unassignedOutlet->id]);
    }

    /**
     * @return array{0: Outlet, 1: Outlet}
     */
    private function actingAsScopedSettingsUser(): array
    {
        $this->seedUserManagementGatePermissions();

        $role = Role::query()->create([
            'name' => '__test_outlet_settings_updater__'.uniqid(),
            'description' => 'Test fixture: settings update scoped outlet',
        ]);

        $settingsViewPermissionId = Permission::query()
            ->where('code', 'settings.view')
            ->value('id');
        self::assertNotNull($settingsViewPermissionId);

        $settingsUpdatePermissionId = Permission::query()
            ->where('code', 'settings.update')
            ->value('id');
        self::assertNotNull($settingsUpdatePermissionId);

        $role->permissions()->sync([(int) $settingsViewPermissionId, (int) $settingsUpdatePermissionId]);

        $user = User::factory()->create([
            'email' => 'outlet-scope-updater-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        $assignedOutlet = Outlet::query()->create([
            'code' => 'out-scope-assigned-'.uniqid(),
            'name' => 'Scoped Assigned Outlet',
            'address' => 'Addr A',
            'phone' => '0830',
            'manager' => 'A',
            'status' => 'active',
        ]);
        $unassignedOutlet = Outlet::query()->create([
            'code' => 'out-scope-unassigned-'.uniqid(),
            'name' => 'Scoped Unassigned Outlet',
            'address' => 'Addr B',
            'phone' => '0831',
            'manager' => 'B',
            'status' => 'active',
        ]);

        $this->fixtureAssignUserToOutlets($user, [$assignedOutlet->id]);
        Passport::actingAs($user);

        return [$assignedOutlet, $unassignedOutlet];
    }
}
