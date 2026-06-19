<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Loyalty\Domain\LoyaltyPointsLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;
use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\LoyaltyEngine\Services\LoyaltyBalanceProjectionService;
use App\Modules\LoyaltyEngine\Services\LoyaltyLedgerService;
use App\Modules\Members\Services\MemberLoyaltyAccountLinker;
use App\Modules\Members\Services\MemberPointsMirrorService;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class MemberPointsMirrorTest extends TestCase
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

    public function test_paid_member_order_mirrors_engine_points_to_crm(): void
    {
        $outletId = $this->createOutletId();
        $member = $this->seedMember($outletId);
        $this->seedSpendBasedProgram($outletId);

        $order = \App\Models\Modules\Orders\Domain\Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => 'MIRROR-EARN-1',
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'member_id' => $member->id,
            'subtotal' => 250000,
            'tax' => 0,
            'total' => 250000,
        ]);

        app(\App\Modules\LoyaltyEngine\Services\LoyaltySpendEarningService::class)->processPaidOrder($order);

        $member->refresh();
        $this->assertNotNull($member->loyalty_account_id);

        $enginePoints = (int) MemberLoyaltyBalance::query()
            ->where('member_id', $member->id)
            ->value('current_points');

        $crmPoints = (int) LoyaltyAccount::query()
            ->whereKey($member->loyalty_account_id)
            ->value('points_balance');

        $this->assertSame(25, $enginePoints);
        $this->assertSame(25, $crmPoints);

        $this->assertDatabaseHas('loyalty_points_ledgers', [
            'loyalty_account_id' => $member->loyalty_account_id,
            'points_delta' => 25,
            'transaction_type' => 'accrual',
        ]);
    }

    public function test_crm_pos_redeem_decreases_engine_and_crm_balances(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutletFixture('MPM');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $member = $this->seedMember((int) $outlet->id);
        $this->seedEngineBalance($member, 200);
        $account = app(MemberLoyaltyAccountLinker::class)->ensureForMember($member->fresh());
        Artisan::call('loyalty:sync-engine-to-crm', ['--memberId' => $member->id]);

        $member->refresh();
        $this->assertSame(200, (int) $account->fresh()->points_balance);

        $this->postJson('/api/v1/crm/loyalty/redemptions', [
            'customerId' => (int) $account->id,
            'outletId' => (int) $outlet->id,
            'pointsUsed' => 80,
            'idempotencyKey' => 'pos-redeem-mirror-001',
        ])->assertCreated();

        $enginePoints = (int) MemberLoyaltyBalance::query()
            ->where('member_id', $member->id)
            ->value('current_points');
        $crmPoints = (int) LoyaltyAccount::query()->whereKey($account->id)->value('points_balance');

        $this->assertSame(120, $enginePoints);
        $this->assertSame(120, $crmPoints);

        $this->assertDatabaseHas('loyalty_member_ledger', [
            'member_id' => $member->id,
            'type' => LoyaltyMemberLedger::TYPE_REDEEM,
            'points' => -80,
            'reference_type' => 'crm_mirror',
        ]);
    }

    public function test_sync_engine_to_crm_command_is_idempotent(): void
    {
        $outlet = $this->createOutletFixture('SYNC');
        $member = $this->seedMember((int) $outlet->id);
        $this->seedEngineBalance($member, 40);

        Artisan::call('loyalty:sync-engine-to-crm', ['--memberId' => $member->id]);
        Artisan::call('loyalty:sync-engine-to-crm', ['--memberId' => $member->id]);

        $account = app(MemberLoyaltyAccountLinker::class)->ensureForMember($member->fresh());
        $this->assertSame(40, (int) $account->fresh()->points_balance);

        $mirrorRows = LoyaltyPointsLedger::query()
            ->where('loyalty_account_id', $account->id)
            ->where('idempotency_key', 'like', MemberPointsMirrorService::MIRROR_ENGINE_PREFIX.'%')
            ->count();

        $this->assertSame(1, $mirrorRows);
    }

    public function test_reconcile_command_reports_no_drift_when_balances_match(): void
    {
        $outlet = $this->createOutletFixture('REC');
        $member = $this->seedMember((int) $outlet->id);
        $this->seedEngineBalance($member, 15);
        Artisan::call('loyalty:sync-engine-to-crm', ['--memberId' => $member->id]);

        Artisan::call('loyalty:reconcile-points-balances', ['--outletId' => (int) $outlet->id]);

        $this->assertStringContainsString('No balance drift detected.', Artisan::output());
    }

    public function test_member_list_returns_unified_points_balance(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutletFixture('LIST');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $create = $this->postJson('/api/v1/members', [
            'outletId' => (int) $outlet->id,
            'name' => 'Points Member',
            'phone' => '081700000001',
            'status' => 'active',
        ])->assertCreated();

        $memberId = (int) $create->json('data.id');
        $member = Member::query()->findOrFail($memberId);
        $this->seedEngineBalance($member, 33);

        $this->getJson('/api/v1/members?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.pointsBalance', 33)
            ->assertJsonPath('data.0.points', 33);
    }

    private function actingAsPosCashier(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_member_points_mirror_cashier__'],
            ['description' => 'POS cashier for mirror tests'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['pos.use'])->pluck('id')->all(),
        );

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_member_points_mirror_admin__'],
            ['description' => 'Members manage for mirror tests'],
        );
        $role->permissions()->sync(
            Permission::query()->where('code', 'members.manage')->pluck('id')->all(),
        );
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function seedOutletFor(User $user): int
    {
        $outletId = $this->createOutletId();
        $user->outlets()->syncWithoutDetaching([$outletId]);

        return $outletId;
    }

    private function createOutletId(): int
    {
        return (int) Outlet::query()->create([
            'name' => 'Mirror Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'mpm-'.uniqid(),
        ])->id;
    }

    private function createOutletFixture(string $prefix): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower($prefix).'-'.uniqid(),
        ]);
    }

    private function seedMember(int $outletId): Member
    {
        return Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'MEM-'.uniqid(),
            'full_name' => 'Mirror Member',
            'name' => 'Mirror Member',
            'phone' => '08'.random_int(1000000000, 9999999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
    }

    private function seedEngineBalance(Member $member, int $points): void
    {
        $ledger = app(LoyaltyLedgerService::class);
        $projection = app(LoyaltyBalanceProjectionService::class);
        $entry = $ledger->createManualAdjustment((int) $member->id, $points, 'Seed balance');
        $projection->applyLedgerEntry($entry);
    }

    private function seedSpendBasedProgram(int $outletId): LoyaltyProgram
    {
        $program = LoyaltyProgram::query()->create([
            'outlet_id' => $outletId,
            'code' => 'SPEND-'.uniqid(),
            'name' => 'Spend points',
            'type' => LoyaltyProgram::TYPE_SPEND_BASED,
            'is_active' => true,
        ]);

        LoyaltyProgramRule::query()->create([
            'loyalty_program_id' => $program->id,
            'rule_type' => LoyaltyProgram::TYPE_SPEND_BASED,
            'config' => [
                'earnPerAmount' => 10000,
                'pointsEarned' => 1,
            ],
        ]);

        return $program;
    }
}
