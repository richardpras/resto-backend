<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
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

class LoyaltyEngineAnalyticsTest extends TestCase
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

    public function test_analytics_summary_for_outlet(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $other = $this->createOutlet('Other');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $activeMember = Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-ACTIVE',
            'full_name' => 'Active',
            'name' => 'Active',
            'phone' => '081111111101',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
        Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-INACTIVE',
            'full_name' => 'Inactive',
            'name' => 'Inactive',
            'phone' => '081111111102',
            'is_active' => false,
            'status' => 'inactive',
            'points' => 0,
        ]);
        $foreign = Member::query()->create([
            'outlet_id' => $other->id,
            'member_no' => 'M-FOREIGN',
            'full_name' => 'Foreign',
            'name' => 'Foreign',
            'phone' => '081111111103',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        LoyaltyMemberLedger::query()->create([
            'member_id' => $activeMember->id,
            'type' => LoyaltyMemberLedger::TYPE_EARN,
            'points' => 40,
            'description' => 'Earn',
        ]);
        LoyaltyMemberLedger::query()->create([
            'member_id' => $activeMember->id,
            'type' => LoyaltyMemberLedger::TYPE_ADJUSTMENT,
            'points' => -5,
            'description' => 'Adjust',
        ]);
        LoyaltyMemberLedger::query()->create([
            'member_id' => $foreign->id,
            'type' => LoyaltyMemberLedger::TYPE_EARN,
            'points' => 999,
            'description' => 'Foreign earn',
        ]);

        MemberLoyaltyBalance::query()->create([
            'member_id' => $activeMember->id,
            'current_points' => 35,
        ]);

        $this->getJson('/api/v1/loyalty-engine/analytics?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.activeMembers', 1)
            ->assertJsonPath('data.totalPointsIssued', 40)
            ->assertJsonPath('data.totalPointsAdjusted', -5)
            ->assertJsonPath('data.totalMemberBalances', 35)
            ->assertJsonPath('data.visitRewardsIssued', 0)
            ->assertJsonPath('data.periodRewardsIssued', 0)
            ->assertJsonPath('data.visitRewardPoints', 0)
            ->assertJsonPath('data.periodRewardPoints', 0)
            ->assertJsonPath('data.redeemTransactions', 0)
            ->assertJsonPath('data.redeemedPoints', 0);
    }

    public function test_analytics_rejects_foreign_outlet_for_scoped_user(): void
    {
        $admin = $this->actingAsMembersManager();
        $allowed = $this->createOutlet('Allowed');
        $blocked = $this->createOutlet('Blocked');
        $this->assignUserToOutlets($admin, [(int) $allowed->id]);

        $this->getJson('/api/v1/loyalty-engine/analytics?outletId='.$blocked->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_analytics_admin__'],
            ['description' => 'Members manage for analytics tests'],
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
            'name' => 'Loyalty Analytics Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lan-'.uniqid(),
        ]);
    }
}
