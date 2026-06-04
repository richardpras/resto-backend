<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomationLog;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaignAudience;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyRewardRedemption;
use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyAnalyticsDashboardService
{
    /** @var list<string> */
    private const ISSUANCE_TYPES = [
        LoyaltyMemberLedger::TYPE_EARN,
        LoyaltyMemberLedger::TYPE_VISIT_REWARD,
        LoyaltyMemberLedger::TYPE_PERIOD_REWARD,
    ];

    /** @var list<string> */
    private const REDEMPTION_TYPES = [
        LoyaltyMemberLedger::TYPE_REDEEM,
        LoyaltyMemberLedger::TYPE_REWARD_REDEEM,
    ];

    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly MemberSegmentService $segmentService,
        private readonly LoyaltyCampaignExecutionService $campaignExecutionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?User $user, int $outletId, Carbon $fromDate, Carbon $toDate): array
    {
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $memberIds = Member::query()
            ->where('outlet_id', $outletId)
            ->pluck('id');

        return [
            'fromDate' => $fromDate->toDateString(),
            'toDate' => $toDate->toDateString(),
            'executiveSummary' => $this->executiveSummary($outletId, $fromDate, $toDate),
            'memberGrowth' => $this->memberGrowth($outletId, $fromDate, $toDate),
            'pointsAnalytics' => $this->pointsAnalytics($memberIds, $fromDate, $toDate),
            'rewardsAnalytics' => $this->rewardsAnalytics($outletId, $fromDate, $toDate),
            'voucherAnalytics' => $this->voucherAnalytics($outletId, $fromDate, $toDate),
            'campaignAnalytics' => $this->campaignAnalytics($outletId, $fromDate, $toDate),
            'segmentAnalytics' => $this->segmentAnalytics($outletId),
            'tierAnalytics' => $this->tierAnalytics($outletId),
            'automationAnalytics' => $this->automationAnalytics($outletId, $fromDate, $toDate),
            'topMembers' => $this->topMembers($outletId, $memberIds, $fromDate, $toDate),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function executiveSummary(int $outletId, Carbon $fromDate, Carbon $toDate): array
    {
        $memberBase = Member::query()->where('outlet_id', $outletId);
        $totalMembers = (int) (clone $memberBase)->count();
        $activeMembers = (int) (clone $memberBase)->where('is_active', true)->count();
        $newMembers = (int) (clone $memberBase)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->count();

        $orderBase = Order::query()
            ->where('outlet_id', $outletId)
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$fromDate, $toDate]);

        $memberRevenue = (float) (clone $orderBase)
            ->whereNotNull('member_id')
            ->sum('total');

        $nonMemberRevenue = (float) (clone $orderBase)
            ->whereNull('member_id')
            ->sum('total');

        $memberOrderCounts = DB::table('orders')
            ->select('member_id', DB::raw('COUNT(*) as order_count'))
            ->where('outlet_id', $outletId)
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('member_id')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('member_id')
            ->get();

        $transactingMembers = $memberOrderCounts->count();
        $repeatMembers = $memberOrderCounts->where('order_count', '>=', 2)->count();
        $repeatCustomerRate = $transactingMembers > 0
            ? round(($repeatMembers / $transactingMembers) * 100, 1)
            : 0.0;

        $averageMemberSpend = $transactingMembers > 0
            ? round($memberRevenue / $transactingMembers, 2)
            : 0.0;

        return [
            'totalMembers' => $totalMembers,
            'activeMembers' => $activeMembers,
            'newMembers' => $newMembers,
            'memberRevenue' => round($memberRevenue, 2),
            'nonMemberRevenue' => round($nonMemberRevenue, 2),
            'repeatCustomerRate' => $repeatCustomerRate,
            'averageMemberSpend' => $averageMemberSpend,
        ];
    }

    /**
     * @return array{daily: list<array{date: string, newMembers: int}>, weekly: list<array{date: string, newMembers: int}>, monthly: list<array{date: string, newMembers: int}>}
     */
    private function memberGrowth(int $outletId, Carbon $fromDate, Carbon $toDate): array
    {
        $dailyRows = DB::table('members')
            ->selectRaw('DATE(created_at) as bucket, COUNT(*) as aggregate')
            ->where('outlet_id', $outletId)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $weeklyBuckets = [];
        $monthlyBuckets = [];
        $createdDates = DB::table('members')
            ->where('outlet_id', $outletId)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->pluck('created_at');

        foreach ($createdDates as $createdAt) {
            $created = Carbon::parse($createdAt);
            $weekKey = $created->copy()->startOfWeek()->toDateString();
            $monthKey = $created->format('Y-m-01');
            $weeklyBuckets[$weekKey] = ($weeklyBuckets[$weekKey] ?? 0) + 1;
            $monthlyBuckets[$monthKey] = ($monthlyBuckets[$monthKey] ?? 0) + 1;
        }

        ksort($weeklyBuckets);
        ksort($monthlyBuckets);

        return [
            'daily' => $this->mapGrowthRows($dailyRows),
            'weekly' => collect($weeklyBuckets)->map(fn (int $count, string $date): array => [
                'date' => $date,
                'newMembers' => $count,
            ])->values()->all(),
            'monthly' => collect($monthlyBuckets)->map(fn (int $count, string $date): array => [
                'date' => $date,
                'newMembers' => $count,
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, object{bucket: string, aggregate: int|string}>  $rows
     * @return list<array{date: string, newMembers: int}>
     */
    private function mapGrowthRows(Collection $rows): array
    {
        return $rows->map(fn ($row): array => [
            'date' => (string) $row->bucket,
            'newMembers' => (int) $row->aggregate,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, int|string>  $memberIds
     * @return array<string, int|list<array{date: string, points: int}>>
     */
    private function pointsAnalytics(Collection $memberIds, Carbon $fromDate, Carbon $toDate): array
    {
        if ($memberIds->isEmpty()) {
            return [
                'pointsIssued' => 0,
                'pointsRedeemed' => 0,
                'pointsExpired' => 0,
                'outstandingPoints' => 0,
                'issuanceTrend' => [],
                'redemptionTrend' => [],
            ];
        }

        $ledgerBase = LoyaltyMemberLedger::query()
            ->whereIn('member_id', $memberIds)
            ->whereBetween('created_at', [$fromDate, $toDate]);

        $pointsIssued = (int) (clone $ledgerBase)
            ->whereIn('type', self::ISSUANCE_TYPES)
            ->where('points', '>', 0)
            ->sum('points');

        $pointsRedeemed = (int) abs((int) (clone $ledgerBase)
            ->whereIn('type', self::REDEMPTION_TYPES)
            ->sum('points'));

        $pointsExpired = (int) abs((int) (clone $ledgerBase)
            ->where('type', LoyaltyMemberLedger::TYPE_EXPIRED)
            ->sum('points'));

        $outstandingPoints = (int) MemberLoyaltyBalance::query()
            ->whereIn('member_id', $memberIds)
            ->sum('current_points');

        $issuanceTrend = DB::table('loyalty_member_ledger')
            ->selectRaw('DATE(created_at) as bucket, SUM(points) as aggregate')
            ->whereIn('member_id', $memberIds->all())
            ->whereIn('type', self::ISSUANCE_TYPES)
            ->where('points', '>', 0)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row): array => [
                'date' => (string) $row->bucket,
                'points' => (int) $row->aggregate,
            ])
            ->values()
            ->all();

        $redemptionTrend = DB::table('loyalty_member_ledger')
            ->selectRaw('DATE(created_at) as bucket, SUM(ABS(points)) as aggregate')
            ->whereIn('member_id', $memberIds->all())
            ->whereIn('type', self::REDEMPTION_TYPES)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row): array => [
                'date' => (string) $row->bucket,
                'points' => (int) $row->aggregate,
            ])
            ->values()
            ->all();

        return [
            'pointsIssued' => $pointsIssued,
            'pointsRedeemed' => $pointsRedeemed,
            'pointsExpired' => $pointsExpired,
            'outstandingPoints' => $outstandingPoints,
            'issuanceTrend' => $issuanceTrend,
            'redemptionTrend' => $redemptionTrend,
        ];
    }

    /**
     * @return array{rewardsRedeemed: int, topRewards: list<array{reward: string, count: int}>}
     */
    private function rewardsAnalytics(int $outletId, Carbon $fromDate, Carbon $toDate): array
    {
        $base = LoyaltyRewardRedemption::query()
            ->where('outlet_id', $outletId)
            ->whereBetween('issued_at', [$fromDate, $toDate]);

        $rewardsRedeemed = (int) (clone $base)->count();

        $topRewards = DB::table('loyalty_reward_redemptions as redemptions')
            ->join('loyalty_rewards as rewards', 'rewards.id', '=', 'redemptions.reward_id')
            ->select('rewards.name as reward_name', DB::raw('COUNT(*) as aggregate'))
            ->where('redemptions.outlet_id', $outletId)
            ->whereBetween('redemptions.issued_at', [$fromDate, $toDate])
            ->groupBy('rewards.id', 'rewards.name')
            ->orderByDesc('aggregate')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'reward' => (string) $row->reward_name,
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();

        return [
            'rewardsRedeemed' => $rewardsRedeemed,
            'topRewards' => $topRewards,
        ];
    }

    /**
     * @return array<string, int|float|list<array<string, int|string>>>
     */
    private function voucherAnalytics(int $outletId, Carbon $fromDate, Carbon $toDate): array
    {
        $memberVoucherBase = MemberVoucher::query()
            ->where('outlet_id', $outletId)
            ->whereBetween('issued_at', [$fromDate, $toDate]);

        $vouchersIssued = (int) (clone $memberVoucherBase)->count();
        $vouchersClaimed = (int) MemberVoucher::query()
            ->where('outlet_id', $outletId)
            ->whereNotNull('claimed_at')
            ->whereBetween('claimed_at', [$fromDate, $toDate])
            ->count();
        $vouchersRedeemed = (int) MemberVoucher::query()
            ->where('outlet_id', $outletId)
            ->where('status', MemberVoucher::STATUS_REDEEMED)
            ->whereBetween('redeemed_at', [$fromDate, $toDate])
            ->count();
        $vouchersExpired = (int) MemberVoucher::query()
            ->where('outlet_id', $outletId)
            ->where('status', MemberVoucher::STATUS_EXPIRED)
            ->whereBetween('expired_at', [$fromDate, $toDate])
            ->count();

        $voucherRedemptionRate = $vouchersIssued > 0
            ? round(($vouchersRedeemed / $vouchersIssued) * 100, 1)
            : 0.0;

        $topVouchers = DB::table('member_vouchers as mv')
            ->join('loyalty_vouchers as lv', 'lv.id', '=', 'mv.voucher_id')
            ->select('lv.code as voucher_code')
            ->selectRaw('COUNT(*) as issued')
            ->selectRaw(
                "SUM(CASE WHEN mv.status = ? AND mv.redeemed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as redeemed",
                [MemberVoucher::STATUS_REDEEMED, $fromDate, $toDate],
            )
            ->where('mv.outlet_id', $outletId)
            ->whereBetween('mv.issued_at', [$fromDate, $toDate])
            ->groupBy('lv.id', 'lv.code')
            ->orderByDesc('issued')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'voucher' => (string) $row->voucher_code,
                'issued' => (int) $row->issued,
                'redeemed' => (int) $row->redeemed,
            ])
            ->values()
            ->all();

        return [
            'vouchersIssued' => $vouchersIssued,
            'vouchersClaimed' => $vouchersClaimed,
            'vouchersRedeemed' => $vouchersRedeemed,
            'vouchersExpired' => $vouchersExpired,
            'voucherRedemptionRate' => $voucherRedemptionRate,
            'topVouchers' => $topVouchers,
        ];
    }

    /**
     * @return array{campaignsCount: int, activeCampaigns: int, campaignPerformance: list<array<string, int|float|string>>}
     */
    private function campaignAnalytics(int $outletId, Carbon $fromDate, Carbon $toDate): array
    {
        $campaignsCount = (int) LoyaltyCampaign::query()->where('outlet_id', $outletId)->count();
        $activeCampaigns = (int) LoyaltyCampaign::query()
            ->where('outlet_id', $outletId)
            ->where('status', LoyaltyCampaign::STATUS_ACTIVE)
            ->count();

        $campaigns = LoyaltyCampaign::query()
            ->where('outlet_id', $outletId)
            ->orderBy('name')
            ->get();

        $campaignPerformance = $campaigns->map(function (LoyaltyCampaign $campaign) use ($fromDate, $toDate): array {
            $note = MemberVoucher::campaignNote((int) $campaign->id);
            $audience = (int) LoyaltyCampaignAudience::query()
                ->where('campaign_id', $campaign->id)
                ->whereBetween('captured_at', [$fromDate, $toDate])
                ->count();

            if ($audience === 0) {
                $audience = $this->campaignExecutionService->countCapturedAudience($campaign);
            }

            $voucherIssued = (int) MemberVoucher::query()
                ->where('outlet_id', $campaign->outlet_id)
                ->where('notes', $note)
                ->whereBetween('issued_at', [$fromDate, $toDate])
                ->count();

            $voucherRedeemed = (int) MemberVoucher::query()
                ->where('outlet_id', $campaign->outlet_id)
                ->where('notes', $note)
                ->where('status', MemberVoucher::STATUS_REDEEMED)
                ->whereBetween('redeemed_at', [$fromDate, $toDate])
                ->count();

            $conversionRate = $voucherIssued > 0
                ? round(($voucherRedeemed / $voucherIssued) * 100, 1)
                : 0.0;

            return [
                'campaign' => (string) $campaign->name,
                'audience' => $audience,
                'voucherIssued' => $voucherIssued,
                'voucherRedeemed' => $voucherRedeemed,
                'conversionRate' => $conversionRate,
            ];
        })->values()->all();

        return [
            'campaignsCount' => $campaignsCount,
            'activeCampaigns' => $activeCampaigns,
            'campaignPerformance' => $campaignPerformance,
        ];
    }

    /**
     * @return array{segmentDistribution: list<array{segment: string, members: int}>}
     */
    private function segmentAnalytics(int $outletId): array
    {
        $segments = MemberSegment::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $segmentDistribution = $segments->map(fn (MemberSegment $segment): array => [
            'segment' => (string) $segment->name,
            'members' => $this->segmentService->countMembers($segment),
        ])->values()->all();

        return ['segmentDistribution' => $segmentDistribution];
    }

    /**
     * @return array{tierDistribution: list<array{tier: string, members: int}>}
     */
    private function tierAnalytics(int $outletId): array
    {
        $tierCounts = DB::table('member_tier_histories as histories')
            ->join('loyalty_tiers as tiers', 'tiers.id', '=', 'histories.tier_id')
            ->select('tiers.name as tier_name', DB::raw('COUNT(DISTINCT histories.member_id) as aggregate'))
            ->where('histories.outlet_id', $outletId)
            ->whereNull('histories.removed_at')
            ->groupBy('tiers.id', 'tiers.name')
            ->orderBy('tiers.name')
            ->get();

        $tierDistribution = $tierCounts->map(fn ($row): array => [
            'tier' => (string) $row->tier_name,
            'members' => (int) $row->aggregate,
        ])->values()->all();

        $assignedMemberIds = DB::table('member_tier_histories')
            ->where('outlet_id', $outletId)
            ->whereNull('removed_at')
            ->distinct()
            ->pluck('member_id');

        $unassigned = (int) Member::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->when($assignedMemberIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $assignedMemberIds))
            ->count();

        if ($unassigned > 0) {
            $tierDistribution[] = [
                'tier' => 'Unassigned',
                'members' => $unassigned,
            ];
        }

        return ['tierDistribution' => $tierDistribution];
    }

    /**
     * @return array{automationExecutions: int, automationSuccess: int, automationFailed: int, topAutomations: list<array{automation: string, executions: int, success: int}>}
     */
    private function automationAnalytics(int $outletId, Carbon $fromDate, Carbon $toDate): array
    {
        $logBase = LoyaltyAutomationLog::query()
            ->whereIn('automation_id', function ($query) use ($outletId): void {
                $query->select('id')
                    ->from('loyalty_automations')
                    ->where('outlet_id', $outletId);
            })
            ->whereBetween('executed_at', [$fromDate, $toDate]);

        $automationExecutions = (int) (clone $logBase)->count();
        $automationSuccess = (int) (clone $logBase)
            ->where('status', LoyaltyAutomationLog::STATUS_SUCCESS)
            ->count();
        $automationFailed = (int) (clone $logBase)
            ->where('status', LoyaltyAutomationLog::STATUS_FAILED)
            ->count();

        $topAutomations = DB::table('loyalty_automation_logs as logs')
            ->join('loyalty_automations as automations', 'automations.id', '=', 'logs.automation_id')
            ->select('automations.name as automation_name')
            ->selectRaw('COUNT(*) as executions')
            ->selectRaw(
                "SUM(CASE WHEN logs.status = ? THEN 1 ELSE 0 END) as success",
                [LoyaltyAutomationLog::STATUS_SUCCESS],
            )
            ->where('automations.outlet_id', $outletId)
            ->whereBetween('logs.executed_at', [$fromDate, $toDate])
            ->groupBy('automations.id', 'automations.name')
            ->orderByDesc('executions')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'automation' => (string) $row->automation_name,
                'executions' => (int) $row->executions,
                'success' => (int) $row->success,
            ])
            ->values()
            ->all();

        return [
            'automationExecutions' => $automationExecutions,
            'automationSuccess' => $automationSuccess,
            'automationFailed' => $automationFailed,
            'topAutomations' => $topAutomations,
        ];
    }

    /**
     * @param  Collection<int, int|string>  $memberIds
     * @return list<array{memberNo: string, name: string, spending: float, points: int}>
     */
    private function topMembers(int $outletId, Collection $memberIds, Carbon $fromDate, Carbon $toDate): array
    {
        if ($memberIds->isEmpty()) {
            return [];
        }

        $spendingRows = DB::table('orders')
            ->select('member_id', DB::raw('SUM(total) as spending'))
            ->where('outlet_id', $outletId)
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('member_id')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('member_id')
            ->orderByDesc('spending')
            ->limit(10)
            ->get()
            ->keyBy('member_id');

        $pointsRows = DB::table('loyalty_member_ledger')
            ->select('member_id', DB::raw('SUM(points) as points'))
            ->whereIn('member_id', $memberIds->all())
            ->whereIn('type', self::ISSUANCE_TYPES)
            ->where('points', '>', 0)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('member_id')
            ->get()
            ->keyBy('member_id');

        $rankedMemberIds = $spendingRows->keys()->take(10);
        if ($rankedMemberIds->isEmpty()) {
            $rankedMemberIds = $pointsRows->sortByDesc('points')->keys()->take(10);
        }

        if ($rankedMemberIds->isEmpty()) {
            return [];
        }

        $members = Member::query()
            ->whereIn('id', $rankedMemberIds->all())
            ->get()
            ->keyBy('id');

        return $rankedMemberIds->map(function ($memberId) use ($members, $spendingRows, $pointsRows): array {
            $member = $members->get($memberId);

            return [
                'memberNo' => (string) ($member?->member_no ?? ''),
                'name' => (string) ($member?->displayName() ?? ''),
                'spending' => round((float) ($spendingRows->get($memberId)?->spending ?? 0), 2),
                'points' => (int) ($pointsRows->get($memberId)?->points ?? 0),
            ];
        })
            ->sortByDesc('spending')
            ->values()
            ->take(10)
            ->all();
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
