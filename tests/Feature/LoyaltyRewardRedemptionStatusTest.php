<?php

namespace Tests\Feature;

use App\Models\Member;
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

class LoyaltyRewardRedemptionStatusTest extends TestCase
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

    public function test_issued_can_be_marked_fulfilled(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $redemption = $this->seedIssuedRedemption((int) $outlet->id);

        $this->patchJson('/api/v1/loyalty-redemptions/'.$redemption->id.'/status', [
            'status' => 'fulfilled',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'fulfilled');

        $this->assertNotNull($redemption->fresh()->fulfilled_at);
    }

    public function test_issued_can_be_marked_cancelled(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $redemption = $this->seedIssuedRedemption((int) $outlet->id);

        $this->patchJson('/api/v1/loyalty-redemptions/'.$redemption->id.'/status', [
            'status' => 'cancelled',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull($redemption->fresh()->cancelled_at);
    }

    public function test_fulfilled_cannot_transition_again(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $redemption = $this->seedIssuedRedemption((int) $outlet->id);
        $redemption->update([
            'status' => LoyaltyRewardRedemption::STATUS_FULFILLED,
            'fulfilled_at' => now(),
        ]);

        $this->patchJson('/api/v1/loyalty-redemptions/'.$redemption->id.'/status', [
            'status' => 'cancelled',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_reward_status_admin__'],
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
            'name' => 'Status Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lrs-'.uniqid(),
        ]);
    }

    private function seedIssuedRedemption(int $outletId): LoyaltyRewardRedemption
    {
        $member = Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'MEM-'.uniqid(),
            'full_name' => 'Status Member',
            'name' => 'Status Member',
            'phone' => '08'.random_int(1000000000, 9999999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $reward = LoyaltyReward::query()->create([
            'outlet_id' => $outletId,
            'code' => 'RWD-'.uniqid(),
            'name' => 'Reward',
            'points_cost' => 100,
            'is_active' => true,
        ]);

        $ledger = app(LoyaltyLedgerService::class);
        $projection = app(LoyaltyBalanceProjectionService::class);
        $entry = $ledger->createManualAdjustment((int) $member->id, 500, 'Seed');
        $projection->applyLedgerEntry($entry);

        $redemption = LoyaltyRewardRedemption::query()->create([
            'outlet_id' => $outletId,
            'member_id' => $member->id,
            'reward_id' => $reward->id,
            'points_spent' => 100,
            'status' => LoyaltyRewardRedemption::STATUS_ISSUED,
            'issued_at' => now(),
        ]);

        $ledgerEntry = $ledger->createRewardRedeem((int) $member->id, (int) $redemption->id, 100);
        $projection->applyLedgerEntry($ledgerEntry);

        return $redemption;
    }
}
