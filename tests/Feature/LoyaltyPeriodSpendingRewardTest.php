<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;
use App\Modules\LoyaltyEngine\Support\LoyaltyPeriodWindow;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\TestCase;

class LoyaltyPeriodSpendingRewardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_monthly_reward_when_minimum_spend_met(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);
        $program = $this->seedPeriodProgram($outletId, 'monthly', 2_000_000, 5);

        $this->recordSpend($outletId, (int) $member->id, 2_500_000, 'PERIOD-MONTH', '2026-06-10 18:00:00');

        Artisan::call('loyalty:process-period-rewards');

        $key = LoyaltyPeriodWindow::forPeriod('monthly', now())['key'];

        $this->assertDatabaseHas('loyalty_member_ledger', [
            'member_id' => $member->id,
            'loyalty_program_id' => $program->id,
            'type' => LoyaltyMemberLedger::TYPE_PERIOD_REWARD,
            'reference_type' => 'period',
            'reference_id' => $key,
            'points' => 125_000,
        ]);
    }

    public function test_weekly_reward(): void
    {
        Carbon::setTestNow('2026-06-11 10:00:00');
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);
        $program = $this->seedPeriodProgram($outletId, 'weekly', 0, 10);

        $this->recordSpend($outletId, (int) $member->id, 500_000, 'PERIOD-WEEK', '2026-06-10 12:00:00');

        Artisan::call('loyalty:process-period-rewards');

        $key = LoyaltyPeriodWindow::forPeriod('weekly', now())['key'];

        $this->assertDatabaseHas('loyalty_member_ledger', [
            'member_id' => $member->id,
            'loyalty_program_id' => $program->id,
            'type' => LoyaltyMemberLedger::TYPE_PERIOD_REWARD,
            'reference_id' => $key,
            'points' => 50_000,
        ]);
    }

    public function test_yearly_reward(): void
    {
        Carbon::setTestNow('2026-08-01 10:00:00');
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);
        $program = $this->seedPeriodProgram($outletId, 'yearly', 0, 2);

        $this->recordSpend($outletId, (int) $member->id, 1_000_000, 'PERIOD-YEAR', '2026-03-15 12:00:00');

        Artisan::call('loyalty:process-period-rewards');

        $key = LoyaltyPeriodWindow::forPeriod('yearly', now())['key'];

        $this->assertDatabaseHas('loyalty_member_ledger', [
            'member_id' => $member->id,
            'loyalty_program_id' => $program->id,
            'reference_id' => $key,
            'points' => 20_000,
        ]);
    }

    public function test_minimum_spend_threshold_blocks_reward(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);
        $this->seedPeriodProgram($outletId, 'monthly', 2_000_000, 5);

        $this->recordSpend($outletId, (int) $member->id, 1_500_000, 'PERIOD-BELOW', '2026-06-12 12:00:00');

        Artisan::call('loyalty:process-period-rewards');

        $this->assertDatabaseCount('loyalty_member_ledger', 0);
    }

    public function test_duplicate_period_reward_not_issued_twice(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);
        $this->seedPeriodProgram($outletId, 'monthly', 0, 5);

        $this->recordSpend($outletId, (int) $member->id, 2_500_000, 'PERIOD-DUP', '2026-06-08 12:00:00');

        Artisan::call('loyalty:process-period-rewards');
        Artisan::call('loyalty:process-period-rewards');

        $this->assertSame(
            1,
            LoyaltyMemberLedger::query()
                ->where('member_id', $member->id)
                ->where('type', LoyaltyMemberLedger::TYPE_PERIOD_REWARD)
                ->count(),
        );
    }

    private function actingAsPosCashier(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_period_cashier__'],
            ['description' => 'POS cashier'],
        );
        $role->permissions()->sync(
            Permission::query()->where('code', 'pos.use')->pluck('id')->all(),
        );
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function seedOutletFor(User $user): int
    {
        $outletId = (int) Outlet::query()->create([
            'name' => 'Period Reward Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lpr-'.uniqid(),
        ])->id;
        $user->outlets()->syncWithoutDetaching([$outletId]);

        return $outletId;
    }

    private function seedMember(int $outletId): Member
    {
        return Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'MEM-'.uniqid(),
            'full_name' => 'Period Member',
            'name' => 'Period Member',
            'phone' => '08'.random_int(1000000000, 9999999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
    }

    private function seedPeriodProgram(int $outletId, string $period, float $minimumSpend, float $rewardPercent): LoyaltyProgram
    {
        $program = LoyaltyProgram::query()->create([
            'outlet_id' => $outletId,
            'code' => 'PERIOD-'.uniqid(),
            'name' => ucfirst($period).' rebate',
            'type' => LoyaltyProgram::TYPE_PERIOD_SPENDING,
            'is_active' => true,
        ]);

        LoyaltyProgramRule::query()->create([
            'loyalty_program_id' => $program->id,
            'rule_type' => LoyaltyProgram::TYPE_PERIOD_SPENDING,
            'config' => [
                'period' => $period,
                'minimum_spend' => $minimumSpend,
                'reward_percent' => $rewardPercent,
            ],
        ]);

        return $program;
    }

    private function recordSpend(int $outletId, int $memberId, float $amount, string $code, string $at): void
    {
        $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'completed',
            'paymentStatus' => 'paid',
            'memberId' => $memberId,
            'items' => [
                ['id' => '1', 'name' => 'Item', 'qty' => 1, 'price' => $amount],
            ],
            'subtotal' => $amount,
            'tax' => 0,
            'total' => $amount,
            'payments' => [
                ['method' => 'cash', 'amount' => $amount],
            ],
        ])->assertCreated();

        $orderId = (int) Order::query()->where('code', $code)->value('id');
        MemberTransaction::query()->where('order_id', $orderId)->update([
            'transaction_at' => $at,
        ]);
    }
}
