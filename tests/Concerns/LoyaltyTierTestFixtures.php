<?php

namespace Tests\Concerns;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\LoyaltyEngine\Services\LoyaltyBalanceProjectionService;
use App\Modules\LoyaltyEngine\Services\LoyaltyLedgerService;
use App\Modules\LoyaltyEngine\Services\LoyaltyTierService;
use Database\Seeders\UserManagementPermissionsSeeder;
use Laravel\Passport\Passport;

trait LoyaltyTierTestFixtures
{
    protected function actingAsTierManager(): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_tier_admin__'],
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

    protected function createTierOutlet(string $suffix = ''): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Tier Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ltier-'.$suffix.uniqid(),
        ]);
    }

    protected function createTierMember(int $outletId, string $label): Member
    {
        return Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'M-'.uniqid(),
            'full_name' => $label,
            'name' => $label,
            'phone' => '08'.random_int(1000000000, 9999999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createTierApi(Outlet $outlet, string $code, string $type, array $config, int $sortOrder = 0, bool $isActive = true): int
    {
        return (int) $this->postJson('/api/v1/loyalty-tiers', [
            'outletId' => $outlet->id,
            'code' => $code,
            'name' => ucfirst(strtolower($code)).' tier',
            'qualificationType' => $type,
            'qualificationConfig' => $config,
            'sortOrder' => $sortOrder,
            'isActive' => $isActive,
        ])
            ->assertCreated()
            ->json('data.id');
    }

    protected function addMemberTransaction(Member $member, float $amount): void
    {
        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $member->outlet_id,
            'code' => 'TIER-'.uniqid(),
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
            'transaction_at' => now(),
        ]);
    }

    protected function seedLifetimePoints(Member $member, Outlet $outlet, int $points): void
    {
        $program = LoyaltyProgram::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'TIER-P'.uniqid(),
            'name' => 'Tier Program',
            'type' => LoyaltyProgram::TYPE_SPEND_BASED,
            'is_active' => true,
        ]);

        $ledger = app(LoyaltyLedgerService::class);
        $projection = app(LoyaltyBalanceProjectionService::class);
        $entry = $ledger->createEarnFromOrder((int) $member->id, (int) $program->id, random_int(1000, 9999), $points)['entry'];
        $projection->applyLedgerEntry($entry);
    }

    protected function recalculateTier(Member $member, Outlet $outlet): void
    {
        app(LoyaltyTierService::class)->recalculateMemberTier((int) $member->id, (int) $outlet->id);
    }
}
