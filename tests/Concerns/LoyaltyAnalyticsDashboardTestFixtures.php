<?php

namespace Tests\Concerns;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaignAudience;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyReward;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyRewardRedemption;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Laravel\Passport\Passport;

trait LoyaltyAnalyticsDashboardTestFixtures
{
    protected function actingAsDashboardManager(): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_dashboard_admin__'],
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

    protected function actingAsDashboardViewerWithoutPermission(): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_dashboard_viewer__'],
            ['description' => 'POS only'],
        );
        $role->permissions()->sync(
            Permission::query()->where('code', 'pos.use')->pluck('id')->all(),
        );
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    protected function createDashboardOutlet(string $suffix = ''): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Dashboard Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ldash-'.$suffix.uniqid(),
        ]);
    }

    protected function createDashboardMember(int $outletId, string $label, bool $active = true): Member
    {
        return Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'M-'.uniqid(),
            'full_name' => $label,
            'name' => $label,
            'phone' => '08'.random_int(1000000000, 9999999999),
            'is_active' => $active,
            'status' => $active ? 'active' : 'inactive',
            'points' => 0,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);
    }

    protected function createPaidOrder(int $outletId, ?int $memberId, float $total, ?\DateTimeInterface $at = null): Order
    {
        $timestamp = $at ?? now();

        return Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => 'ORD-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'member_id' => $memberId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    protected function seedLedgerEarn(Member $member, int $points, ?\DateTimeInterface $at = null): void
    {
        LoyaltyMemberLedger::query()->create([
            'member_id' => $member->id,
            'type' => LoyaltyMemberLedger::TYPE_EARN,
            'points' => $points,
            'description' => 'Dashboard earn',
            'created_at' => $at ?? now(),
        ]);
    }

    protected function seedLedgerRedeem(Member $member, int $points, ?\DateTimeInterface $at = null): void
    {
        LoyaltyMemberLedger::query()->create([
            'member_id' => $member->id,
            'type' => LoyaltyMemberLedger::TYPE_REDEEM,
            'points' => -$points,
            'description' => 'Dashboard redeem',
            'created_at' => $at ?? now(),
        ]);
    }

    protected function createDashboardVoucher(int $outletId, string $code = 'WELCOME10'): LoyaltyVoucher
    {
        return LoyaltyVoucher::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => 'Welcome Voucher',
            'voucher_type' => LoyaltyVoucher::TYPE_MANUAL,
            'value_type' => LoyaltyVoucher::VALUE_FIXED_AMOUNT,
            'value' => 10000,
            'is_active' => true,
        ]);
    }

    protected function issueMemberVoucher(
        Member $member,
        LoyaltyVoucher $voucher,
        string $status = MemberVoucher::STATUS_ISSUED,
        ?\DateTimeInterface $issuedAt = null,
    ): MemberVoucher {
        return MemberVoucher::query()->create([
            'outlet_id' => $member->outlet_id,
            'member_id' => $member->id,
            'voucher_id' => $voucher->id,
            'voucher_code' => $voucher->code,
            'status' => $status,
            'issued_at' => $issuedAt ?? now(),
            'redeemed_at' => $status === MemberVoucher::STATUS_REDEEMED ? ($issuedAt ?? now()) : null,
        ]);
    }

    protected function createDashboardReward(int $outletId, string $code = 'COFFEE'): LoyaltyReward
    {
        return LoyaltyReward::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => 'Free Coffee',
            'points_cost' => 100,
            'is_active' => true,
        ]);
    }

    protected function seedRewardRedemption(Member $member, LoyaltyReward $reward, ?\DateTimeInterface $at = null): void
    {
        LoyaltyRewardRedemption::query()->create([
            'outlet_id' => $member->outlet_id,
            'member_id' => $member->id,
            'reward_id' => $reward->id,
            'points_spent' => 100,
            'status' => LoyaltyRewardRedemption::STATUS_ISSUED,
            'issued_at' => $at ?? now(),
        ]);
    }

    protected function createDashboardSegment(int $outletId): MemberSegment
    {
        return MemberSegment::query()->create([
            'outlet_id' => $outletId,
            'code' => 'VIP',
            'name' => 'VIP',
            'segment_type' => 'vip_spender',
            'config_json' => ['minimum_spending' => 100000],
            'is_active' => true,
        ]);
    }

    protected function createDashboardCampaign(int $outletId, MemberSegment $segment): LoyaltyCampaign
    {
        return LoyaltyCampaign::query()->create([
            'outlet_id' => $outletId,
            'code' => 'BDAY-JUN',
            'name' => 'Birthday June',
            'segment_id' => $segment->id,
            'campaign_type' => 'audience',
            'status' => LoyaltyCampaign::STATUS_ACTIVE,
            'activated_at' => now()->subDays(3),
        ]);
    }

    protected function captureCampaignAudience(LoyaltyCampaign $campaign, Member $member): void
    {
        LoyaltyCampaignAudience::query()->create([
            'campaign_id' => $campaign->id,
            'member_id' => $member->id,
            'captured_at' => now()->subDays(2),
        ]);
    }
}
