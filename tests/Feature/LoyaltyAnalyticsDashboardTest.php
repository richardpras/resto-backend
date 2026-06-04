<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\LoyaltyAnalyticsDashboardTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyAnalyticsDashboardTest extends TestCase
{
    use LoyaltyAnalyticsDashboardTestFixtures;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_executive_summary_and_member_growth(): void
    {
        $admin = $this->actingAsDashboardManager();
        $outlet = $this->createDashboardOutlet('exec');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $repeatMember = $this->createDashboardMember((int) $outlet->id, 'Repeat Buyer');
        $singleMember = $this->createDashboardMember((int) $outlet->id, 'Single Buyer');
        $this->createDashboardMember((int) $outlet->id, 'Inactive', false);

        $this->createPaidOrder((int) $outlet->id, (int) $repeatMember->id, 200000);
        $this->createPaidOrder((int) $outlet->id, (int) $repeatMember->id, 150000);
        $this->createPaidOrder((int) $outlet->id, (int) $singleMember->id, 100000);
        $this->createPaidOrder((int) $outlet->id, null, 50000);

        $fromDate = now()->subDays(30)->toDateString();
        $toDate = now()->toDateString();

        $response = $this->getJson("/api/v1/loyalty-analytics/dashboard?outletId={$outlet->id}&fromDate={$fromDate}&toDate={$toDate}")
            ->assertOk();

        $response
            ->assertJsonPath('data.executiveSummary.totalMembers', 3)
            ->assertJsonPath('data.executiveSummary.activeMembers', 2)
            ->assertJsonPath('data.executiveSummary.newMembers', 3)
            ->assertJsonPath('data.executiveSummary.memberRevenue', 450000)
            ->assertJsonPath('data.executiveSummary.nonMemberRevenue', 50000)
            ->assertJsonPath('data.executiveSummary.repeatCustomerRate', 50)
            ->assertJsonPath('data.executiveSummary.averageMemberSpend', 225000)
            ->assertJsonStructure([
                'data' => [
                    'memberGrowth' => ['daily', 'weekly', 'monthly'],
                ],
            ]);
    }

    public function test_points_and_voucher_analytics(): void
    {
        $admin = $this->actingAsDashboardManager();
        $outlet = $this->createDashboardOutlet('points');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createDashboardMember((int) $outlet->id, 'Points Member');

        $this->seedLedgerEarn($member, 500, now()->subDays(2));
        $this->seedLedgerRedeem($member, 100, now()->subDay());

        $voucher = $this->createDashboardVoucher((int) $outlet->id, 'WELCOME10');
        $this->issueMemberVoucher($member, $voucher, MemberVoucher::STATUS_ISSUED, now()->subDays(2));
        MemberVoucher::query()->create([
            'outlet_id' => $outlet->id,
            'member_id' => $member->id,
            'voucher_id' => $voucher->id,
            'voucher_code' => $voucher->code.'-R',
            'status' => MemberVoucher::STATUS_REDEEMED,
            'issued_at' => now()->subDays(2),
            'redeemed_at' => now()->subDay(),
        ]);

        $fromDate = now()->subDays(7)->toDateString();
        $toDate = now()->toDateString();

        $this->getJson("/api/v1/loyalty-analytics/dashboard?outletId={$outlet->id}&fromDate={$fromDate}&toDate={$toDate}")
            ->assertOk()
            ->assertJsonPath('data.pointsAnalytics.pointsIssued', 500)
            ->assertJsonPath('data.pointsAnalytics.pointsRedeemed', 100)
            ->assertJsonPath('data.voucherAnalytics.vouchersIssued', 2)
            ->assertJsonPath('data.voucherAnalytics.vouchersRedeemed', 1)
            ->assertJsonPath('data.voucherAnalytics.voucherRedemptionRate', 50)
            ->assertJsonPath('data.voucherAnalytics.topVouchers.0.voucher', 'WELCOME10');
    }

    public function test_campaign_and_rewards_analytics(): void
    {
        $admin = $this->actingAsDashboardManager();
        $outlet = $this->createDashboardOutlet('campaign');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createDashboardMember((int) $outlet->id, 'Campaign Member');
        $segment = $this->createDashboardSegment((int) $outlet->id);
        $campaign = $this->createDashboardCampaign((int) $outlet->id, $segment);
        $this->captureCampaignAudience($campaign, $member);

        $voucher = $this->createDashboardVoucher((int) $outlet->id, 'CAMP10');
        MemberVoucher::query()->create([
            'outlet_id' => $outlet->id,
            'member_id' => $member->id,
            'voucher_id' => $voucher->id,
            'voucher_code' => $voucher->code,
            'status' => MemberVoucher::STATUS_REDEEMED,
            'issued_at' => now()->subDays(2),
            'redeemed_at' => now()->subDay(),
            'notes' => MemberVoucher::campaignNote((int) $campaign->id),
        ]);

        $reward = $this->createDashboardReward((int) $outlet->id);
        $this->seedRewardRedemption($member, $reward);

        $fromDate = now()->subDays(7)->toDateString();
        $toDate = now()->toDateString();

        $this->getJson("/api/v1/loyalty-analytics/dashboard?outletId={$outlet->id}&fromDate={$fromDate}&toDate={$toDate}")
            ->assertOk()
            ->assertJsonPath('data.rewardsAnalytics.rewardsRedeemed', 1)
            ->assertJsonPath('data.rewardsAnalytics.topRewards.0.reward', 'Free Coffee')
            ->assertJsonPath('data.campaignAnalytics.campaignsCount', 1)
            ->assertJsonPath('data.campaignAnalytics.activeCampaigns', 1)
            ->assertJsonPath('data.campaignAnalytics.campaignPerformance.0.campaign', 'Birthday June');
    }

    public function test_defaults_to_last_30_days_when_dates_omitted(): void
    {
        $admin = $this->actingAsDashboardManager();
        $outlet = $this->createDashboardOutlet('default');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $this->getJson("/api/v1/loyalty-analytics/dashboard?outletId={$outlet->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'fromDate',
                    'toDate',
                    'executiveSummary',
                    'pointsAnalytics',
                    'voucherAnalytics',
                    'campaignAnalytics',
                ],
            ]);
    }
}
