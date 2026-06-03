<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyReward;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyRewardCatalogTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    public function test_create_update_and_list_rewards(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $create = $this->postJson('/api/v1/loyalty-rewards', [
            'outletId' => $outlet->id,
            'code' => 'FREE_COFFEE',
            'name' => 'Free Coffee',
            'description' => 'Redeem for one coffee',
            'pointsCost' => 500,
        ])->assertCreated();

        $rewardId = (int) $create->json('data.id');

        $this->patchJson("/api/v1/loyalty-rewards/{$rewardId}", [
            'name' => 'Free Coffee XL',
            'pointsCost' => 600,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Free Coffee XL')
            ->assertJsonPath('data.pointsCost', 600);

        $this->getJson('/api/v1/loyalty-rewards?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.code', 'FREE_COFFEE')
            ->assertJsonPath('data.0.pointsCost', 600);
    }

    public function test_activate_and_deactivate_reward(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $rewardId = (int) $this->postJson('/api/v1/loyalty-rewards', [
            'outletId' => $outlet->id,
            'code' => 'VIP-PKG',
            'name' => 'VIP Package',
            'pointsCost' => 5000,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/loyalty-rewards/{$rewardId}/activation", [
            'isActive' => false,
        ])->assertOk()->assertJsonPath('data.isActive', false);

        $this->getJson('/api/v1/loyalty-rewards?outletId='.$outlet->id.'&isActive=0')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $rewardId);
    }

    public function test_outlet_isolation_blocks_foreign_reward(): void
    {
        $admin = $this->actingAsMembersManager();
        $allowed = $this->createOutlet('Allowed');
        $blocked = $this->createOutlet('Blocked');
        $this->assignUserToOutlets($admin, [(int) $allowed->id]);

        $foreignId = (int) LoyaltyReward::query()->create([
            'outlet_id' => $blocked->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign',
            'points_cost' => 100,
            'is_active' => true,
        ])->id;

        $this->getJson('/api/v1/loyalty-rewards/'.$foreignId)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    public function test_validation_rejects_duplicate_code_and_invalid_points(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $this->postJson('/api/v1/loyalty-rewards', [
            'outletId' => $outlet->id,
            'code' => 'DUP',
            'name' => 'First',
            'pointsCost' => 100,
        ])->assertCreated();

        $this->postJson('/api/v1/loyalty-rewards', [
            'outletId' => $outlet->id,
            'code' => 'dup',
            'name' => 'Second',
            'pointsCost' => 200,
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);

        $this->postJson('/api/v1/loyalty-rewards', [
            'outletId' => $outlet->id,
            'code' => 'ZERO',
            'name' => 'Zero',
            'pointsCost' => 0,
        ])->assertStatus(422);
    }

    public function test_member_profile_shows_active_rewards_only(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $member = \App\Models\Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'MEM-RWD',
            'full_name' => 'Reward Viewer',
            'name' => 'Reward Viewer',
            'phone' => '081233344455',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        LoyaltyReward::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'COFFEE',
            'name' => 'Free Coffee',
            'points_cost' => 500,
            'is_active' => true,
        ]);
        LoyaltyReward::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'DESSERT',
            'name' => 'Free Dessert',
            'points_cost' => 1000,
            'is_active' => false,
        ]);
        LoyaltyReward::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'VIP',
            'name' => 'VIP Package',
            'points_cost' => 5000,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/members/'.$member->id.'/profile?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.availableRewards')
            ->assertJsonPath('data.availableRewards.0.name', 'Free Coffee')
            ->assertJsonPath('data.availableRewards.0.pointsCost', 500)
            ->assertJsonPath('data.availableRewards.1.name', 'VIP Package')
            ->assertJsonPath('data.availableRewards.1.pointsCost', 5000);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_reward_admin__'],
            ['description' => 'Members manage'],
        );
        $role->permissions()->sync(
            Permission::query()->where('code', 'members.manage')->pluck('id')->all(),
        );
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function createOutlet(string $suffix = ''): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Reward Catalog Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lrc-'.uniqid(),
        ]);
    }
}
