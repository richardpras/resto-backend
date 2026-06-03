<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyReward;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyRewardRedemption;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\LoyaltyEngine\Services\LoyaltyBalanceProjectionService;
use App\Modules\LoyaltyEngine\Services\LoyaltyLedgerService;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyRewardRedemptionTest extends TestCase
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

    public function test_redeem_reward_creates_ledger_and_reduces_balance(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $member = $this->seedMemberWithBalance((int) $outlet->id, 2000);
        $reward = $this->seedReward((int) $outlet->id, 'FREE_COFFEE', 500);

        $response = $this->postJson('/api/v1/members/'.$member->id.'/redeem-reward', [
            'outletId' => $outlet->id,
            'rewardId' => $reward->id,
            'notes' => 'Counter redemption',
        ])
            ->assertCreated()
            ->assertJsonPath('data.rewardName', 'Free Coffee')
            ->assertJsonPath('data.pointsSpent', 500)
            ->assertJsonPath('data.currentBalance', 1500)
            ->assertJsonPath('data.status', 'issued');

        $redemptionId = (int) $response->json('data.redemptionId');

        $this->assertDatabaseHas('loyalty_reward_redemptions', [
            'id' => $redemptionId,
            'member_id' => $member->id,
            'reward_id' => $reward->id,
            'points_spent' => 500,
            'status' => 'issued',
        ]);

        $this->assertDatabaseHas('loyalty_member_ledger', [
            'member_id' => $member->id,
            'type' => LoyaltyMemberLedger::TYPE_REWARD_REDEEM,
            'points' => -500,
            'reference_type' => 'reward_redemption',
            'reference_id' => (string) $redemptionId,
        ]);

        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 1500,
        ]);
    }

    public function test_insufficient_balance_rejected(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $member = $this->seedMemberWithBalance((int) $outlet->id, 100);
        $reward = $this->seedReward((int) $outlet->id, 'EXPENSIVE', 500);

        $this->postJson('/api/v1/members/'.$member->id.'/redeem-reward', [
            'outletId' => $outlet->id,
            'rewardId' => $reward->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['points']);

        $this->assertDatabaseCount('loyalty_reward_redemptions', 0);
    }

    public function test_inactive_reward_rejected(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $member = $this->seedMemberWithBalance((int) $outlet->id, 1000);
        $reward = $this->seedReward((int) $outlet->id, 'OFF', 100, false);

        $this->postJson('/api/v1/members/'.$member->id.'/redeem-reward', [
            'outletId' => $outlet->id,
            'rewardId' => $reward->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rewardId']);
    }

    public function test_outlet_isolation_blocks_foreign_reward(): void
    {
        $admin = $this->actingAsMembersManager();
        $allowed = $this->createOutlet('Allowed');
        $blocked = $this->createOutlet('Blocked');
        $this->assignUserToOutlets($admin, [(int) $allowed->id]);

        $member = $this->seedMemberWithBalance((int) $allowed->id, 2000);
        $foreignReward = $this->seedReward((int) $blocked->id, 'FOREIGN', 100);

        $this->postJson('/api/v1/members/'.$member->id.'/redeem-reward', [
            'outletId' => $allowed->id,
            'rewardId' => $foreignReward->id,
        ])->assertStatus(422);
    }

    public function test_profile_lists_reward_redemptions(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $member = $this->seedMemberWithBalance((int) $outlet->id, 2000);
        $reward = $this->seedReward((int) $outlet->id, 'PROFILE-RWD', 300);

        $this->postJson('/api/v1/members/'.$member->id.'/redeem-reward', [
            'outletId' => $outlet->id,
            'rewardId' => $reward->id,
        ])->assertCreated();

        $this->getJson('/api/v1/members/'.$member->id.'/profile?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.rewardRedemptions')
            ->assertJsonPath('data.rewardRedemptions.0.rewardName', 'Free Coffee')
            ->assertJsonPath('data.rewardRedemptions.0.pointsSpent', 300)
            ->assertJsonPath('data.rewardRedemptions.0.status', 'issued');
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_reward_redeem_admin__'],
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
            'name' => 'Reward Redeem Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lrr-'.uniqid(),
        ]);
    }

    private function seedMemberWithBalance(int $outletId, int $points): Member
    {
        $member = Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'MEM-'.uniqid(),
            'full_name' => 'Redeem Member',
            'name' => 'Redeem Member',
            'phone' => '08'.random_int(1000000000, 9999999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $ledger = app(LoyaltyLedgerService::class);
        $projection = app(LoyaltyBalanceProjectionService::class);
        $entry = $ledger->createManualAdjustment((int) $member->id, $points, 'Seed');
        $projection->applyLedgerEntry($entry);

        return $member->fresh();
    }

    private function seedReward(int $outletId, string $code, int $pointsCost, bool $active = true): LoyaltyReward
    {
        return LoyaltyReward::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => 'Free Coffee',
            'points_cost' => $pointsCost,
            'is_active' => $active,
        ]);
    }
}
