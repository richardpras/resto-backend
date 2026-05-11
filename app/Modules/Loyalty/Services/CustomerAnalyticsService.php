<?php

namespace App\Modules\Loyalty\Services;

use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Loyalty\Domain\LoyaltyPointsLedger;
use App\Models\Modules\Loyalty\Domain\LoyaltyRewardRedemption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerAnalyticsService
{
    /** @return array<int, array<string, mixed>> */
    public function timeline(LoyaltyAccount $account): array
    {
        $ledgerItems = LoyaltyPointsLedger::query()
            ->where('loyalty_account_id', $account->id)
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(static fn (LoyaltyPointsLedger $ledger): array => [
                'type' => 'ledger',
                'id' => (int) $ledger->id,
                'occurredAt' => $ledger->created_at?->toIso8601String(),
                'payload' => [
                    'transactionType' => (string) $ledger->transaction_type,
                    'pointsDelta' => (int) $ledger->points_delta,
                    'balanceAfter' => (int) $ledger->balance_after,
                ],
            ]);

        $redemptionItems = LoyaltyRewardRedemption::query()
            ->where('loyalty_account_id', $account->id)
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(static fn (LoyaltyRewardRedemption $redemption): array => [
                'type' => 'redemption',
                'id' => (int) $redemption->id,
                'occurredAt' => $redemption->created_at?->toIso8601String(),
                'payload' => [
                    'rewardCode' => (string) $redemption->reward_code,
                    'pointsCost' => (int) $redemption->points_cost,
                    'status' => (string) $redemption->status,
                ],
            ]);

        return Collection::make($ledgerItems)
            ->concat($redemptionItems)
            ->sortByDesc('occurredAt')
            ->values()
            ->all();
    }

    /**
     * @param list<int> $outletIds
     * @return array<string,mixed>
     */
    public function metricsForOutlets(array $outletIds): array
    {
        if ($outletIds === []) {
            return [
                'activeCustomers' => 0,
                'repeatVisitRate' => 0.0,
                'loyaltyPointsIssued' => 0,
                'loyaltyPointsRedeemed' => 0,
                'topTierCounts' => [],
                'customerRetentionIndicators' => [
                    'customersWithRecentVisit' => 0,
                    'inactiveCustomers30d' => 0,
                ],
            ];
        }

        $accounts = DB::table('loyalty_accounts')->whereIn('outlet_id', $outletIds);
        $totalCustomers = (int) (clone $accounts)->count();
        $repeatCustomers = (int) (clone $accounts)->where('lifetime_visits', '>', 1)->count();
        $recentVisit = (int) (clone $accounts)->whereNotNull('last_activity_at')->where('last_activity_at', '>=', now()->subDays(30))->count();
        $inactiveThirtyDays = (int) (clone $accounts)->where(function ($q): void {
            $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', now()->subDays(30));
        })->count();

        $pointsIssued = (int) DB::table('loyalty_points_ledgers')
            ->whereIn('outlet_id', $outletIds)
            ->where('points_delta', '>', 0)
            ->sum('points_delta');
        $pointsRedeemed = abs((int) DB::table('loyalty_points_ledgers')
            ->whereIn('outlet_id', $outletIds)
            ->where('points_delta', '<', 0)
            ->sum('points_delta'));

        $tierCounts = DB::table('loyalty_accounts as la')
            ->leftJoin('loyalty_membership_tiers as t', 't.id', '=', 'la.current_tier_id')
            ->whereIn('la.outlet_id', $outletIds)
            ->selectRaw("COALESCE(NULLIF(t.code, ''), t.name, 'UNASSIGNED') as tier_key, COUNT(*) as aggregate")
            ->groupBy('tier_key')
            ->pluck('aggregate', 'tier_key')
            ->toArray();

        return [
            'activeCustomers' => $totalCustomers,
            'repeatVisitRate' => $totalCustomers > 0 ? round(($repeatCustomers / $totalCustomers) * 100, 2) : 0.0,
            'loyaltyPointsIssued' => $pointsIssued,
            'loyaltyPointsRedeemed' => $pointsRedeemed,
            'topTierCounts' => $tierCounts,
            'customerRetentionIndicators' => [
                'customersWithRecentVisit' => $recentVisit,
                'inactiveCustomers30d' => $inactiveThirtyDays,
            ],
        ];
    }
}
