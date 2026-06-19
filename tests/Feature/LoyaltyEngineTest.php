<?php

namespace Tests\Feature;

use App\Models\Member;
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
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\TestCase;

class LoyaltyEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    public function test_spend_program_earns_points_on_paid_member_order(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);
        $this->seedSpendBasedProgram($outletId);

        $order = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => 'LOY-EARN-1',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'completed',
            'paymentStatus' => 'paid',
            'memberId' => $member->id,
            'items' => [
                ['id' => '1', 'name' => 'Set Menu', 'qty' => 1, 'price' => 250000],
            ],
            'subtotal' => 250000,
            'tax' => 0,
            'total' => 250000,
            'payments' => [
                ['method' => 'cash', 'amount' => 250000],
            ],
        ])->assertCreated();

        $orderId = (int) $order->json('data.id');

        $this->assertDatabaseHas('loyalty_member_ledger', [
            'member_id' => $member->id,
            'type' => 'earn',
            'reference_type' => 'order',
            'reference_id' => (string) $orderId,
            'points' => 25,
        ]);

        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 25,
        ]);
    }

    public function test_spend_earn_mirrors_points_to_crm_account(): void
    {
        $outletId = $this->createOutletId();
        $member = $this->seedMember($outletId);
        $this->seedSpendBasedProgram($outletId);

        $order = \App\Models\Modules\Orders\Domain\Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => 'LOY-CRM-MIRROR',
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
        $this->assertSame(25, (int) \App\Models\Modules\Loyalty\Domain\LoyaltyAccount::query()
            ->whereKey($member->loyalty_account_id)
            ->value('points_balance'));
    }

    public function test_spend_program_earns_from_subtotal_not_tax(): void
    {
        $outletId = $this->createOutletId();
        $member = $this->seedMember($outletId);

        $program = LoyaltyProgram::query()->create([
            'outlet_id' => $outletId,
            'code' => 'SPEND-SUB-'.uniqid(),
            'name' => 'Subtotal spend program',
            'type' => LoyaltyProgram::TYPE_SPEND_BASED,
            'is_active' => true,
        ]);
        LoyaltyProgramRule::query()->create([
            'loyalty_program_id' => $program->id,
            'rule_type' => LoyaltyProgram::TYPE_SPEND_BASED,
            'config' => [
                'earnPerAmount' => 1000,
                'pointsEarned' => 1,
            ],
        ]);

        $order = \App\Models\Modules\Orders\Domain\Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => 'LOY-SUBTOTAL',
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'member_id' => $member->id,
            'subtotal' => 1000000,
            'tax' => 100000,
            'total' => 1100000,
        ]);

        app(\App\Modules\LoyaltyEngine\Services\LoyaltySpendEarningService::class)->processPaidOrder($order);

        $this->assertDatabaseHas('loyalty_member_ledger', [
            'member_id' => $member->id,
            'type' => 'earn',
            'reference_type' => 'order',
            'reference_id' => (string) $order->id,
            'points' => 1000,
        ]);
    }

    public function test_paid_order_without_member_earns_nothing(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $this->seedSpendBasedProgram($outletId);

        $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => 'LOY-NO-MEMBER',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'completed',
            'paymentStatus' => 'paid',
            'items' => [
                ['id' => '1', 'name' => 'Item', 'qty' => 1, 'price' => 100000],
            ],
            'subtotal' => 100000,
            'tax' => 0,
            'total' => 100000,
            'payments' => [
                ['method' => 'cash', 'amount' => 100000],
            ],
        ])->assertCreated();

        $this->assertDatabaseCount('loyalty_member_ledger', 0);
        $this->assertDatabaseCount('member_loyalty_balances', 0);
    }

    public function test_paid_order_without_active_program_earns_nothing(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);

        $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => 'LOY-NO-PROG',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'completed',
            'paymentStatus' => 'paid',
            'memberId' => $member->id,
            'items' => [
                ['id' => '1', 'name' => 'Item', 'qty' => 1, 'price' => 100000],
            ],
            'subtotal' => 100000,
            'tax' => 0,
            'total' => 100000,
            'payments' => [
                ['method' => 'cash', 'amount' => 100000],
            ],
        ])->assertCreated();

        $this->assertDatabaseCount('loyalty_member_ledger', 0);
    }

    public function test_loyalty_history_appears_in_member_profile(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);
        $this->seedSpendBasedProgram($outletId);

        $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => 'LOY-PROFILE',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'completed',
            'paymentStatus' => 'paid',
            'memberId' => $member->id,
            'items' => [
                ['id' => '1', 'name' => 'Item', 'qty' => 1, 'price' => 50000],
            ],
            'subtotal' => 50000,
            'tax' => 0,
            'total' => 50000,
            'payments' => [
                ['method' => 'cash', 'amount' => 50000],
            ],
        ])->assertCreated();

        $this->getJson('/api/v1/members/'.$member->id.'/profile?outletId='.$outletId)
            ->assertOk()
            ->assertJsonPath('data.currentPoints', 5)
            ->assertJsonCount(1, 'data.loyaltyHistory')
            ->assertJsonPath('data.loyaltyHistory.0.type', 'earn')
            ->assertJsonPath('data.loyaltyHistory.0.points', 5)
            ->assertJsonPath('data.loyaltyHistory.0.referenceType', 'order');
    }

    public function test_manual_adjustment_updates_balance_via_ledger(): void
    {
        $member = $this->seedMember($this->createOutletId());

        $ledgerService = app(LoyaltyLedgerService::class);
        $projection = app(LoyaltyBalanceProjectionService::class);

        $entry = $ledgerService->createManualAdjustment($member->id, 15, 'Welcome bonus');
        $projection->applyLedgerEntry($entry);

        $this->assertDatabaseHas('loyalty_member_ledger', [
            'member_id' => $member->id,
            'type' => 'adjustment',
            'points' => 15,
        ]);
        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 15,
        ]);

        $negative = $ledgerService->createManualAdjustment($member->id, -5, 'Correction');
        $projection->applyLedgerEntry($negative);

        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 10,
        ]);
    }

    public function test_duplicate_paid_processing_does_not_double_balance(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);
        $member = $this->seedMember($outletId);
        $this->seedSpendBasedProgram($outletId);

        $order = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => 'LOY-DEDUPE',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'completed',
            'paymentStatus' => 'paid',
            'memberId' => $member->id,
            'items' => [
                ['id' => '1', 'name' => 'Item', 'qty' => 1, 'price' => 10000],
            ],
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'payments' => [
                ['method' => 'cash', 'amount' => 10000],
            ],
        ])->assertCreated();

        $orderId = (int) $order->json('data.id');
        $orderModel = \App\Models\Modules\Orders\Domain\Order::query()->findOrFail($orderId);

        app(\App\Modules\LoyaltyEngine\Services\LoyaltySpendEarningService::class)->processPaidOrder($orderModel);

        $this->assertDatabaseCount('loyalty_member_ledger', 1);
        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 1,
        ]);
    }

    public function test_period_spending_program_can_be_stored_without_scheduler_earn(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);

        $program = LoyaltyProgram::query()->create([
            'outlet_id' => $outletId,
            'code' => 'PERIOD-MONTHLY-'.uniqid(),
            'name' => 'Monthly rebate',
            'type' => LoyaltyProgram::TYPE_PERIOD_SPENDING,
            'is_active' => true,
        ]);

        LoyaltyProgramRule::query()->create([
            'loyalty_program_id' => $program->id,
            'config' => [
                'period' => 'monthly',
                'percentage' => 2,
            ],
        ]);

        $this->assertDatabaseHas('loyalty_programs', [
            'id' => $program->id,
            'type' => 'period_spending',
        ]);

        $member = $this->seedMember($outletId);

        $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => 'LOY-PERIOD',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'completed',
            'paymentStatus' => 'paid',
            'memberId' => $member->id,
            'items' => [
                ['id' => '1', 'name' => 'Item', 'qty' => 1, 'price' => 100000],
            ],
            'subtotal' => 100000,
            'tax' => 0,
            'total' => 100000,
            'payments' => [
                ['method' => 'cash', 'amount' => 100000],
            ],
        ])->assertCreated();

        $this->assertDatabaseCount('loyalty_member_ledger', 0);
    }

    public function test_balance_projection_rebuild_matches_ledger_sum(): void
    {
        $member = $this->seedMember($this->createOutletId());
        $ledgerService = app(LoyaltyLedgerService::class);
        $projection = app(LoyaltyBalanceProjectionService::class);

        $first = $ledgerService->createManualAdjustment($member->id, 10, 'A');
        $second = $ledgerService->createManualAdjustment($member->id, -3, 'B');
        $projection->applyLedgerEntry($first);
        $projection->applyLedgerEntry($second);

        MemberLoyaltyBalance::query()->where('member_id', $member->id)->update(['current_points' => 999]);

        $rebuilt = $projection->rebuildForMember((int) $member->id);
        $ledgerSum = (int) LoyaltyMemberLedger::query()->where('member_id', $member->id)->sum('points');

        $this->assertSame($ledgerSum, (int) $rebuilt->current_points);
        $this->assertSame(7, (int) $rebuilt->current_points);
    }

    private function actingAsPosCashier(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_engine_cashier__'],
            ['description' => 'POS cashier for loyalty engine tests'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['pos.use'])->pluck('id')->all(),
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
            'name' => 'Loyalty Engine Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'le-'.uniqid(),
        ])->id;
    }

    private function seedMember(int $outletId): Member
    {
        return Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'MEM-'.uniqid(),
            'full_name' => 'Test Member',
            'name' => 'Test Member',
            'phone' => '08'.random_int(1000000000, 9999999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
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
