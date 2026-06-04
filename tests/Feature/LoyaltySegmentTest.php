<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyReward;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyRewardRedemption;
use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
use App\Models\Modules\Orders\Domain\Order;
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

class LoyaltySegmentTest extends TestCase
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

    public function test_vip_spender_segment_matches_high_spending_members(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $vip = $this->createMember((int) $outlet->id, 'VIP');
        $regular = $this->createMember((int) $outlet->id, 'Regular');
        $this->addTransaction($vip, 6000000);
        $this->addTransaction($regular, 100000);

        $segmentId = $this->createSegmentApi($outlet, 'VIP', 'vip_spender', ['minimum_spending' => 5000000]);

        $this->getJson("/api/v1/member-segments/{$segmentId}/preview")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.members.0.id', (string) $vip->id);
    }

    public function test_frequent_visitor_segment_matches_visit_threshold(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $frequent = $this->createMember((int) $outlet->id, 'Frequent');
        $occasional = $this->createMember((int) $outlet->id, 'Occasional');

        for ($i = 0; $i < 20; $i++) {
            $this->addTransaction($frequent, 10000);
        }
        $this->addTransaction($occasional, 10000);

        $segmentId = $this->createSegmentApi($outlet, 'FREQ', 'frequent_visitor', ['minimum_visits' => 20]);

        $this->getJson("/api/v1/member-segments/{$segmentId}/preview")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.members.0.id', (string) $frequent->id);
    }

    public function test_birthday_month_segment_matches_current_month(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $birthdayMember = $this->createMember((int) $outlet->id, 'June Baby', '1990-06-10');
        $otherMember = $this->createMember((int) $outlet->id, 'March Baby', '1990-03-10');

        $segmentId = $this->createSegmentApi($outlet, 'BDAY', 'birthday_month', []);

        $this->getJson("/api/v1/member-segments/{$segmentId}/preview")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.members.0.id', (string) $birthdayMember->id);

        unset($otherMember);
        Carbon::setTestNow();
    }

    public function test_inactive_member_segment_matches_stale_visits(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $inactive = $this->createMember((int) $outlet->id, 'Inactive');
        $active = $this->createMember((int) $outlet->id, 'Active');
        $this->addTransaction($inactive, 50000, now()->subDays(100));
        $this->addTransaction($active, 50000, now()->subDays(10));

        $segmentId = $this->createSegmentApi($outlet, 'INACT', 'inactive_member', ['inactive_days' => 90]);

        $this->getJson("/api/v1/member-segments/{$segmentId}/preview")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.members.0.id', (string) $inactive->id);

        Carbon::setTestNow();
    }

    public function test_never_redeemed_segment_excludes_members_with_redemptions(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $never = $this->createMember((int) $outlet->id, 'Never');
        $redeemed = $this->createMember((int) $outlet->id, 'Redeemed');
        $reward = LoyaltyReward::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'COFFEE',
            'name' => 'Coffee',
            'points_cost' => 100,
            'is_active' => true,
        ]);
        LoyaltyRewardRedemption::query()->create([
            'outlet_id' => $outlet->id,
            'member_id' => $redeemed->id,
            'reward_id' => $reward->id,
            'points_spent' => 100,
            'status' => LoyaltyRewardRedemption::STATUS_ISSUED,
            'issued_at' => now(),
        ]);

        $segmentId = $this->createSegmentApi($outlet, 'NORED', 'never_redeemed', []);

        $this->getJson("/api/v1/member-segments/{$segmentId}/preview")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.members.0.id', (string) $never->id);
    }

    public function test_expiring_soon_segment_matches_members_with_upcoming_expiry(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $program = LoyaltyProgram::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'EXP-SEG',
            'name' => 'Expiry program',
            'type' => LoyaltyProgram::TYPE_SPEND_BASED,
            'is_active' => true,
            'expiry_enabled' => true,
            'expiry_days' => 365,
        ]);

        $expiring = $this->createMember((int) $outlet->id, 'Expiring');
        $safe = $this->createMember((int) $outlet->id, 'Safe');

        $this->seedEarnEntry($expiring, $program, 100, now()->subDays(340));
        $this->seedEarnEntry($safe, $program, 100, now()->subDays(100));

        $segmentId = $this->createSegmentApi($outlet, 'EXPSOON', 'expiring_soon', ['days_before_expiry' => 30]);

        $this->getJson("/api/v1/member-segments/{$segmentId}/preview")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.members.0.id', (string) $expiring->id);

        Carbon::setTestNow();
    }

    public function test_outlet_isolation_blocks_foreign_segment_access(): void
    {
        $admin = $this->actingAsMembersManager();
        $outletA = $this->createOutlet('A');
        $outletB = $this->createOutlet('B');
        $this->assignUserToOutlets($admin, [(int) $outletA->id]);

        $foreignSegmentId = (int) MemberSegment::query()->create([
            'outlet_id' => $outletB->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign',
            'segment_type' => MemberSegment::TYPE_NEVER_REDEEMED,
            'config_json' => [],
            'is_active' => true,
        ])->id;

        $this->getJson('/api/v1/member-segments/'.$foreignSegmentId)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    public function test_activation_controls_preview_count(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $this->createMember((int) $outlet->id, 'Anyone');

        $segmentId = $this->createSegmentApi($outlet, 'NR', 'never_redeemed', []);

        $this->getJson("/api/v1/member-segments/{$segmentId}/preview")
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->patchJson("/api/v1/member-segments/{$segmentId}/activation", [
            'isActive' => false,
        ])->assertOk();

        $this->getJson("/api/v1/member-segments/{$segmentId}/preview")
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_segment_admin__'],
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
            'name' => 'Segment Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lseg-'.$suffix.uniqid(),
        ]);
    }

    private function createMember(int $outletId, string $label, ?string $birthDate = null): Member
    {
        return Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'M-'.uniqid(),
            'full_name' => $label,
            'name' => $label,
            'phone' => '08'.random_int(1000000000, 9999999999),
            'birth_date' => $birthDate,
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
    }

    private function addTransaction(Member $member, float $amount, ?Carbon $at = null): void
    {
        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $member->outlet_id,
            'code' => 'SEG-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => $amount,
            'tax' => 0,
            'total' => $amount,
            'member_id' => $member->id,
        ]);

        MemberTransaction::query()->create([
            'member_id' => $member->id,
            'order_id' => $order->id,
            'total_amount' => $amount,
            'transaction_at' => $at ?? now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createSegmentApi(Outlet $outlet, string $code, string $type, array $config): int
    {
        return (int) $this->postJson('/api/v1/member-segments', [
            'outletId' => $outlet->id,
            'code' => $code,
            'name' => $code.' segment',
            'segmentType' => $type,
            'config' => $config,
            'isActive' => true,
        ])
            ->assertCreated()
            ->json('data.id');
    }

    private function seedEarnEntry(Member $member, LoyaltyProgram $program, int $points, Carbon $createdAt): LoyaltyMemberLedger
    {
        $ledger = app(LoyaltyLedgerService::class);
        $projection = app(LoyaltyBalanceProjectionService::class);
        $entry = $ledger->createEarnFromOrder((int) $member->id, (int) $program->id, random_int(1000, 9999), $points)['entry'];
        $entry->created_at = $createdAt;
        $entry->save();
        $projection->applyLedgerEntry($entry);

        return $entry;
    }
}
