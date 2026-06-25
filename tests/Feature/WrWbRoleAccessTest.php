<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\CustomerDemo\WrWbMay2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\TestCase;

class WrWbRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(WrWbMay2026Seeder::class);
    }

    public function test_wr_wb_admin_has_all_permissions_role(): void
    {
        $role = Role::query()->where('name', 'WR WB Admin')->firstOrFail();
        $totalPermissions = \App\Models\Modules\UserManagement\Domain\Permission::query()->count();

        $this->assertSame($totalPermissions, $role->permissions()->count());
    }

    public function test_wr_wb_owner_lacks_platform_permissions(): void
    {
        $codes = Role::query()->where('name', 'WR WB Owner')->firstOrFail()
            ->permissions()->pluck('code')->all();

        foreach (['settings.manage', 'users.manage', 'users.view', 'roles.view'] as $code) {
            $this->assertNotContains($code, $codes, "WR WB Owner must not have {$code}");
        }

        $this->assertContains('settings.view', $codes);
        $this->assertContains('settings.update', $codes);
    }

    public function test_wr_wb_manager_has_operational_settings_only(): void
    {
        $codes = Role::query()->where('name', 'WR WB Manager')->firstOrFail()
            ->permissions()->pluck('code')->all();

        $this->assertContains('settings.view', $codes);
        $this->assertContains('settings.update', $codes);
        $this->assertNotContains('settings.manage', $codes);
    }

    public function test_admin_can_patch_merchant_and_create_outlet(): void
    {
        $admin = User::query()->where('email', 'admin@wrwb.demo')->firstOrFail();
        Passport::actingAs($admin);

        $before = $this->getJson('/api/v1/merchant-settings')->assertOk()->json('data');
        $payload = array_merge($before, ['name' => 'WR WB Merchant Updated']);

        $this->patchJson('/api/v1/merchant-settings', $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'WR WB Merchant Updated');

        $this->postJson('/api/v1/outlets', [
            'name' => 'WR WB Branch',
            'address' => 'Jl. Branch',
            'phone' => '021-5550199',
            'manager' => 'Branch Manager',
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'WR WB Branch');
    }

    public function test_owner_cannot_patch_merchant_or_create_outlet_but_can_patch_assigned_outlet(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-WRWB')->firstOrFail();
        $owner = User::query()->where('email', 'owner@wrwb.demo')->firstOrFail();
        Passport::actingAs($owner);

        $before = $this->getJson('/api/v1/merchant-settings')->assertOk()->json('data');
        $payload = array_merge($before, ['name' => 'Owner Should Not Save']);

        $this->patchJson('/api/v1/merchant-settings', $payload)->assertForbidden();

        $this->postJson('/api/v1/outlets', [
            'name' => 'Owner Outlet',
            'address' => 'Addr',
            'phone' => '0800',
            'manager' => 'M',
            'status' => 'active',
        ])->assertForbidden();

        $this->patchJson('/api/v1/outlets/'.$outlet->id, [
            'code' => $outlet->code,
            'name' => 'WR WB Updated By Owner',
            'address' => $outlet->address,
            'phone' => $outlet->phone,
            'manager' => $outlet->manager,
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.name', 'WR WB Updated By Owner');
    }

    public function test_manager_cannot_patch_merchant_but_can_manage_taxes(): void
    {
        $manager = User::query()->where('email', 'manager@wrwb.demo')->firstOrFail();
        Passport::actingAs($manager);

        $before = $this->getJson('/api/v1/merchant-settings')->assertOk()->json('data');
        $payload = array_merge($before, ['name' => 'Manager Should Not Save']);

        $this->patchJson('/api/v1/merchant-settings', $payload)->assertForbidden();

        $this->postJson('/api/v1/taxes', [
            'id' => 'wrwb-demo-tax',
            'name' => 'WR WB Demo Tax',
            'type' => 'percentage',
            'value' => 10,
            'applyDineIn' => true,
            'applyTakeaway' => true,
            'inclusive' => false,
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'WR WB Demo Tax');
    }
}
