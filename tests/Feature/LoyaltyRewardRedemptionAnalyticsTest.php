<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyReward;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyRewardRedemption;
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

class LoyaltyRewardRedemptionAnalyticsTest extends TestCase
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

    public function test_analytics_includes_reward_redemption_counters(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $member = Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-AN',
            'full_name' => 'Analytics',
            'name' => 'Analytics',
            'phone' => '081200011122',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $reward = LoyaltyReward::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'R1',
            'name' => 'Coffee',
            'points_cost' => 500,
            'is_active' => true,
        ]);

        LoyaltyRewardRedemption::query()->create([
            'outlet_id' => $outlet->id,
            'member_id' => $member->id,
            'reward_id' => $reward->id,
            'points_spent' => 500,
            'status' => LoyaltyRewardRedemption::STATUS_ISSUED,
            'issued_at' => now(),
        ]);
        LoyaltyRewardRedemption::query()->create([
            'outlet_id' => $outlet->id,
            'member_id' => $member->id,
            'reward_id' => $reward->id,
            'points_spent' => 300,
            'status' => LoyaltyRewardRedemption::STATUS_FULFILLED,
            'issued_at' => now(),
            'fulfilled_at' => now(),
        ]);
        LoyaltyRewardRedemption::query()->create([
            'outlet_id' => $outlet->id,
            'member_id' => $member->id,
            'reward_id' => $reward->id,
            'points_spent' => 200,
            'status' => LoyaltyRewardRedemption::STATUS_CANCELLED,
            'issued_at' => now(),
            'cancelled_at' => now(),
        ]);

        $this->getJson('/api/v1/loyalty-engine/analytics?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.rewardRedemptions', 3)
            ->assertJsonPath('data.fulfilledRewardRedemptions', 1)
            ->assertJsonPath('data.cancelledRewardRedemptions', 1)
            ->assertJsonPath('data.pointsSpentOnRewards', 1000);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_reward_redeem_analytics__'],
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

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Redeem Analytics '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lra2-'.uniqid(),
        ]);
    }
}
