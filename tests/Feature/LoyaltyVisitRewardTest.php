<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;
use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\Members\Services\MemberTransactionRecorder;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\TestCase;

class LoyaltyVisitRewardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    public function test_milestone_reward_issued_on_tenth_visit(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);
        $this->seedVisitProgram($outletId, 10, 100);

        for ($i = 1; $i <= 10; $i++) {
            $this->placePaidOrder($outletId, (int) $member->id, 10000, 'VISIT-'.$i);
        }

        $this->assertDatabaseHas('loyalty_member_ledger', [
            'member_id' => $member->id,
            'type' => LoyaltyMemberLedger::TYPE_VISIT_REWARD,
            'reference_type' => 'visit',
            'reference_id' => '10',
            'points' => 100,
        ]);

        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 100,
        ]);
    }

    public function test_duplicate_milestone_not_rewarded_twice(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);
        $this->seedVisitProgram($outletId, 10, 100);

        for ($i = 1; $i <= 10; $i++) {
            $this->placePaidOrder($outletId, (int) $member->id, 10000, 'VISIT-DUP-'.$i);
        }

        $order = Order::query()->where('code', 'VISIT-DUP-10')->firstOrFail();
        app(MemberTransactionRecorder::class)->recordForPaidOrder($order);

        $this->assertSame(
            1,
            LoyaltyMemberLedger::query()
                ->where('member_id', $member->id)
                ->where('type', LoyaltyMemberLedger::TYPE_VISIT_REWARD)
                ->where('reference_id', '10')
                ->count(),
        );
    }

    public function test_non_eligible_visit_count_does_not_issue_reward(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);
        $this->seedVisitProgram($outletId, 10, 100);

        for ($i = 1; $i <= 9; $i++) {
            $this->placePaidOrder($outletId, (int) $member->id, 10000, 'VISIT-NE-'.$i);
        }

        $this->assertDatabaseMissing('loyalty_member_ledger', [
            'member_id' => $member->id,
            'type' => LoyaltyMemberLedger::TYPE_VISIT_REWARD,
        ]);
        $this->assertDatabaseCount('member_loyalty_balances', 0);
    }

    private function actingAsPosCashier(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_visit_cashier__'],
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
            'name' => 'Visit Reward Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lvr-'.uniqid(),
        ])->id;
        $user->outlets()->syncWithoutDetaching([$outletId]);

        return $outletId;
    }

    private function seedMember(int $outletId): Member
    {
        return Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'MEM-'.uniqid(),
            'full_name' => 'Visit Member',
            'name' => 'Visit Member',
            'phone' => '08'.random_int(1000000000, 9999999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
    }

    private function seedVisitProgram(int $outletId, int $threshold, int $pointsAwarded): void
    {
        $program = LoyaltyProgram::query()->create([
            'outlet_id' => $outletId,
            'code' => 'VISIT-'.uniqid(),
            'name' => 'Visit milestones',
            'type' => LoyaltyProgram::TYPE_VISIT_BASED,
            'is_active' => true,
        ]);

        LoyaltyProgramRule::query()->create([
            'loyalty_program_id' => $program->id,
            'rule_type' => LoyaltyProgram::TYPE_VISIT_BASED,
            'config' => [
                'visit_threshold' => $threshold,
                'points_awarded' => $pointsAwarded,
            ],
        ]);
    }

    private function placePaidOrder(int $outletId, int $memberId, float $total, string $code): void
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
                ['id' => '1', 'name' => 'Item', 'qty' => 1, 'price' => $total],
            ],
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'payments' => [
                ['method' => 'cash', 'amount' => $total],
            ],
        ])->assertCreated();
    }
}
