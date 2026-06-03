<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyReward;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyRewardRedemption;
use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Validation\ValidationException;

class LoyaltyEngineAnalyticsService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array<string, int|float>
     */
    public function summary(?User $user, int $outletId): array
    {
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $memberQuery = Member::query()->where('outlet_id', $outletId);
        $activeMembers = (int) (clone $memberQuery)->where('is_active', true)->count();
        $memberIds = $memberQuery->pluck('id');

        $rewardRedemptionStats = $this->rewardRedemptionStatsForOutlet($outletId);

        if ($memberIds->isEmpty()) {
            return array_merge($this->emptySummary(), $rewardRedemptionStats);
        }

        $totalPointsIssued = (int) LoyaltyMemberLedger::query()
            ->whereIn('member_id', $memberIds)
            ->where('type', LoyaltyMemberLedger::TYPE_EARN)
            ->where('points', '>', 0)
            ->sum('points');

        $totalPointsAdjusted = (int) LoyaltyMemberLedger::query()
            ->whereIn('member_id', $memberIds)
            ->where('type', LoyaltyMemberLedger::TYPE_ADJUSTMENT)
            ->sum('points');

        $totalMemberBalances = (int) MemberLoyaltyBalance::query()
            ->whereIn('member_id', $memberIds)
            ->sum('current_points');

        $visitRewardsIssued = (int) LoyaltyMemberLedger::query()
            ->whereIn('member_id', $memberIds)
            ->where('type', LoyaltyMemberLedger::TYPE_VISIT_REWARD)
            ->count();

        $periodRewardsIssued = (int) LoyaltyMemberLedger::query()
            ->whereIn('member_id', $memberIds)
            ->where('type', LoyaltyMemberLedger::TYPE_PERIOD_REWARD)
            ->count();

        $visitRewardPoints = (int) LoyaltyMemberLedger::query()
            ->whereIn('member_id', $memberIds)
            ->where('type', LoyaltyMemberLedger::TYPE_VISIT_REWARD)
            ->where('points', '>', 0)
            ->sum('points');

        $periodRewardPoints = (int) LoyaltyMemberLedger::query()
            ->whereIn('member_id', $memberIds)
            ->where('type', LoyaltyMemberLedger::TYPE_PERIOD_REWARD)
            ->where('points', '>', 0)
            ->sum('points');

        $redeemTransactions = (int) LoyaltyMemberLedger::query()
            ->whereIn('member_id', $memberIds)
            ->where('type', LoyaltyMemberLedger::TYPE_REDEEM)
            ->count();

        $redeemedPoints = (int) abs((int) LoyaltyMemberLedger::query()
            ->whereIn('member_id', $memberIds)
            ->where('type', LoyaltyMemberLedger::TYPE_REDEEM)
            ->sum('points'));

        $expiredTransactions = (int) LoyaltyMemberLedger::query()
            ->whereIn('member_id', $memberIds)
            ->where('type', LoyaltyMemberLedger::TYPE_EXPIRED)
            ->count();

        $expiredPoints = (int) abs((int) LoyaltyMemberLedger::query()
            ->whereIn('member_id', $memberIds)
            ->where('type', LoyaltyMemberLedger::TYPE_EXPIRED)
            ->sum('points'));

        $activeRewards = (int) LoyaltyReward::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->count();

        return array_merge([
            'activeMembers' => $activeMembers,
            'totalPointsIssued' => $totalPointsIssued,
            'totalPointsAdjusted' => $totalPointsAdjusted,
            'totalMemberBalances' => $totalMemberBalances,
            'visitRewardsIssued' => $visitRewardsIssued,
            'periodRewardsIssued' => $periodRewardsIssued,
            'visitRewardPoints' => $visitRewardPoints,
            'periodRewardPoints' => $periodRewardPoints,
            'redeemTransactions' => $redeemTransactions,
            'redeemedPoints' => $redeemedPoints,
            'expiredTransactions' => $expiredTransactions,
            'expiredPoints' => $expiredPoints,
            'activeRewards' => $activeRewards,
        ], $rewardRedemptionStats);
    }

    /**
     * @return array<string, int>
     */
    private function rewardRedemptionStatsForOutlet(int $outletId): array
    {
        $base = LoyaltyRewardRedemption::query()->where('outlet_id', $outletId);

        return [
            'rewardRedemptions' => (int) (clone $base)->count(),
            'fulfilledRewardRedemptions' => (int) (clone $base)
                ->where('status', LoyaltyRewardRedemption::STATUS_FULFILLED)
                ->count(),
            'cancelledRewardRedemptions' => (int) (clone $base)
                ->where('status', LoyaltyRewardRedemption::STATUS_CANCELLED)
                ->count(),
            'pointsSpentOnRewards' => (int) (clone $base)->sum('points_spent'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptySummary(): array
    {
        return [
            'activeMembers' => 0,
            'totalPointsIssued' => 0,
            'totalPointsAdjusted' => 0,
            'totalMemberBalances' => 0,
            'visitRewardsIssued' => 0,
            'periodRewardsIssued' => 0,
            'visitRewardPoints' => 0,
            'periodRewardPoints' => 0,
            'redeemTransactions' => 0,
            'redeemedPoints' => 0,
            'expiredTransactions' => 0,
            'expiredPoints' => 0,
            'activeRewards' => 0,
            'rewardRedemptions' => 0,
            'fulfilledRewardRedemptions' => 0,
            'cancelledRewardRedemptions' => 0,
            'pointsSpentOnRewards' => 0,
        ];
    }

    private function assertOutletAllowed(?User $user, int $outletId): void
    {
        if ($user === null) {
            return;
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if ($allowed !== null && ! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outlet is not allowed for this user.'],
            ]);
        }
    }
}
