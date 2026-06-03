<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\LoyaltyEngine\Services\LoyaltyBalanceProjectionService;
use App\Modules\LoyaltyEngine\Services\LoyaltyLedgerService;
use Carbon\Carbon;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyExpiryTest extends TestCase
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

    public function test_points_expire_after_configured_days(): void
    {
        Carbon::setTestNow('2026-01-02 12:00:00');

        $outlet = $this->createOutlet();
        $member = $this->seedMember((int) $outlet->id);
        $program = $this->seedExpiryProgram((int) $outlet->id, 365);
        $earning = $this->seedEarning($member, $program, 100, now()->subDays(366));

        Artisan::call('loyalty:process-expiry');

        $this->assertDatabaseHas('loyalty_member_ledger', [
            'member_id' => $member->id,
            'type' => LoyaltyMemberLedger::TYPE_EXPIRED,
            'points' => -100,
            'reference_type' => 'expiry',
            'reference_id' => (string) $earning->id,
        ]);

        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 0,
        ]);

        Carbon::setTestNow();
    }

    public function test_points_do_not_expire_before_threshold(): void
    {
        Carbon::setTestNow('2026-01-02 12:00:00');

        $outlet = $this->createOutlet();
        $member = $this->seedMember((int) $outlet->id);
        $program = $this->seedExpiryProgram((int) $outlet->id, 365);
        $this->seedEarning($member, $program, 100, now()->subDays(100));

        Artisan::call('loyalty:process-expiry');

        $this->assertDatabaseCount('loyalty_member_ledger', 1);
        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 100,
        ]);

        Carbon::setTestNow();
    }

    public function test_expiry_disabled_program_ignores_old_earnings(): void
    {
        $outlet = $this->createOutlet();
        $member = $this->seedMember((int) $outlet->id);
        $program = LoyaltyProgram::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'NO-EXP',
            'name' => 'No expiry',
            'type' => LoyaltyProgram::TYPE_SPEND_BASED,
            'is_active' => true,
            'expiry_enabled' => false,
            'expiry_days' => null,
        ]);
        $this->seedEarning($member, $program, 100, now()->subDays(500));

        Artisan::call('loyalty:process-expiry');

        $this->assertDatabaseCount('loyalty_member_ledger', 1);
        $this->assertDatabaseMissing('loyalty_member_ledger', [
            'type' => LoyaltyMemberLedger::TYPE_EXPIRED,
        ]);
    }

    public function test_idempotent_processing_does_not_double_expire(): void
    {
        Carbon::setTestNow('2026-06-01 00:00:00');

        $outlet = $this->createOutlet();
        $member = $this->seedMember((int) $outlet->id);
        $program = $this->seedExpiryProgram((int) $outlet->id, 30);
        $this->seedEarning($member, $program, 50, now()->subDays(31));

        Artisan::call('loyalty:process-expiry');
        Artisan::call('loyalty:process-expiry');

        $this->assertDatabaseCount('loyalty_member_ledger', 2);
        $this->assertEquals(1, LoyaltyMemberLedger::query()
            ->where('type', LoyaltyMemberLedger::TYPE_EXPIRED)
            ->count());

        Carbon::setTestNow();
    }

    public function test_profile_shows_expiry_policy_and_history(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->seedMember((int) $outlet->id);
        $program = $this->seedExpiryProgram((int) $outlet->id, 90);
        $earning = $this->seedEarning($member, $program, 80, now()->subDays(91));

        Artisan::call('loyalty:process-expiry');

        $this->getJson('/api/v1/members/'.$member->id.'/profile?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.expiryPolicy.enabled', true)
            ->assertJsonPath('data.expiryPolicy.days', 90)
            ->assertJsonPath('data.expiredPointsTotal', 80)
            ->assertJsonPath('data.expiryHistory.0.type', 'expired')
            ->assertJsonPath('data.expiryHistory.0.points', -80)
            ->assertJsonPath('data.expiryHistory.0.referenceType', 'expiry')
            ->assertJsonPath('data.expiryHistory.0.referenceId', (string) $earning->id);
    }

    private function seedMember(int $outletId): Member
    {
        return Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'M-EXP-'.uniqid(),
            'full_name' => 'Expiry Member',
            'name' => 'Expiry Member',
            'phone' => '0812'.random_int(10000000, 99999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
    }

    private function seedExpiryProgram(int $outletId, int $days): LoyaltyProgram
    {
        return LoyaltyProgram::query()->create([
            'outlet_id' => $outletId,
            'code' => 'EXP-'.uniqid(),
            'name' => 'Expiry program',
            'type' => LoyaltyProgram::TYPE_SPEND_BASED,
            'is_active' => true,
            'expiry_enabled' => true,
            'expiry_days' => $days,
        ]);
    }

    private function seedEarning(
        Member $member,
        LoyaltyProgram $program,
        int $points,
        Carbon $createdAt,
    ): LoyaltyMemberLedger {
        $ledger = app(LoyaltyLedgerService::class);
        $projection = app(LoyaltyBalanceProjectionService::class);

        $result = $ledger->createEarnFromOrder(
            memberId: (int) $member->id,
            loyaltyProgramId: (int) $program->id,
            orderId: random_int(1000, 9999),
            points: $points,
        );

        $entry = $result['entry'];
        $entry->created_at = $createdAt;
        $entry->save();

        MemberLoyaltyBalance::query()->where('member_id', $member->id)->delete();
        $projection->rebuildForMember((int) $member->id);

        return $entry->fresh() ?? $entry;
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_expiry_admin__'],
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
            'name' => 'Expiry Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lexp-'.uniqid(),
        ]);
    }
}
