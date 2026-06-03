<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
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

class LoyaltyRedeemTest extends TestCase
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

    public function test_successful_redeem_reduces_balance_and_writes_ledger(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->seedMemberWithBalance((int) $outlet->id, 1000);

        $this->postJson('/api/v1/members/'.$member->id.'/redeem', [
            'outletId' => $outlet->id,
            'points' => 300,
            'description' => 'Birthday redemption',
        ])
            ->assertOk()
            ->assertJsonPath('data.memberId', (string) $member->id)
            ->assertJsonPath('data.redeemedPoints', 300)
            ->assertJsonPath('data.currentBalance', 700);

        $this->assertDatabaseHas('loyalty_member_ledger', [
            'member_id' => $member->id,
            'type' => LoyaltyMemberLedger::TYPE_REDEEM,
            'points' => -300,
            'reference_type' => 'redeem',
        ]);

        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 700,
        ]);
    }

    public function test_insufficient_balance_returns_422(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->seedMemberWithBalance((int) $outlet->id, 100);

        $this->postJson('/api/v1/members/'.$member->id.'/redeem', [
            'outletId' => $outlet->id,
            'points' => 300,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['points']);

        $this->assertDatabaseCount('loyalty_member_ledger', 1);
        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 100,
        ]);
    }

    public function test_inactive_member_blocked(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->seedMemberWithBalance((int) $outlet->id, 500);
        $member->update(['is_active' => false, 'status' => 'inactive']);

        $this->postJson('/api/v1/members/'.$member->id.'/redeem', [
            'outletId' => $outlet->id,
            'points' => 100,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['memberId']);

        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 500,
        ]);
    }

    public function test_redeem_appears_in_member_profile_history(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->seedMemberWithBalance((int) $outlet->id, 200);

        $this->postJson('/api/v1/members/'.$member->id.'/redeem', [
            'outletId' => $outlet->id,
            'points' => 50,
            'description' => 'Manual redeem',
        ])->assertOk();

        $this->getJson('/api/v1/members/'.$member->id.'/profile?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.currentPoints', 150)
            ->assertJsonPath('data.loyaltyHistory.0.type', 'redeem')
            ->assertJsonPath('data.loyaltyHistory.0.points', -50);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_redeem_admin__'],
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
            'name' => 'Redeem Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lrd-'.uniqid(),
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
        $entry = $ledger->createManualAdjustment((int) $member->id, $points, 'Seed balance');
        $projection->applyLedgerEntry($entry);

        return $member->fresh();
    }
}
