<?php

namespace App\Modules\GiftCards\Services;

use App\Models\Modules\GiftCards\Domain\GiftCardIssuance;
use App\Models\Modules\GiftCards\Domain\GiftCardLedger;
use App\Models\Modules\GiftCards\Domain\GiftCardRedemptionSettlement;
use App\Models\User;
use App\Modules\Accounting\Services\GlBalanceService;

final class GiftCardReconciliationService
{
    public function __construct(
        private readonly GlBalanceService $glBalanceService,
    ) {}

    /** @return array<string, mixed> */
    public function report(?User $actor, ?int $outletId): array
    {
        unset($actor);

        $issuanceQuery = GiftCardIssuance::query();
        $ledgerQuery = GiftCardLedger::query();
        $settlementQuery = GiftCardRedemptionSettlement::query();

        if ($outletId !== null && $outletId > 0) {
            $issuanceQuery->where('outlet_id', $outletId);
            $ledgerQuery->where('outlet_id', $outletId);
            $settlementQuery->where('outlet_id', $outletId);
        }

        $giftCardOutstanding = round((float) (clone $issuanceQuery)
            ->where('instrument_type', 'gift_card')
            ->whereIn('status', ['active', 'depleted'])
            ->sum('balance_amount'), 2);

        $storeCreditOutstanding = round((float) (clone $issuanceQuery)
            ->where('instrument_type', 'store_credit')
            ->whereIn('status', ['active', 'depleted'])
            ->sum('balance_amount'), 2);

        $subledgerOutstanding = round($giftCardOutstanding + $storeCreditOutstanding, 2);

        $issuedAmount = round((float) (clone $issuanceQuery)->sum('issued_amount'), 2);

        $redeemedAmount = round(abs((float) (clone $ledgerQuery)
            ->where('transaction_type', 'redeem')
            ->sum('amount_delta')), 2);

        $expiredAmount = round(abs((float) (clone $ledgerQuery)
            ->where('transaction_type', 'expire')
            ->sum('amount_delta')), 2);

        $pendingSettlements = (int) (clone $settlementQuery)->where('status', 'pending')->count();
        $settledSettlements = (int) (clone $settlementQuery)->where('status', 'settled')->count();

        $giftCardGl = $this->glBalanceService->mappedRuleBalance(null, $outletId, 'pos', 'pos.redemption.gift_card');
        $storeCreditGl = $this->glBalanceService->mappedRuleBalance(null, $outletId, 'pos', 'pos.redemption.store_credit');
        $glLiabilityBalance = round($giftCardGl + $storeCreditGl, 2);

        $giftCardVariance = round($giftCardOutstanding - $giftCardGl, 2);
        $storeCreditVariance = round($storeCreditOutstanding - $storeCreditGl, 2);
        $difference = round($subledgerOutstanding - $glLiabilityBalance, 2);

        $pendingGlCount = (int) GiftCardIssuance::query()
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereIn('status', ['active', 'depleted'])
            ->where(function ($q): void {
                $q->whereNull('meta')
                    ->orWhere('meta->accountingStatus', 'pending_gl');
            })
            ->count();

        $status = $this->resolveStatus($difference, $giftCardVariance, $storeCreditVariance, $pendingGlCount);

        return [
            'subledgerOutstanding' => $subledgerOutstanding,
            'glLiabilityBalance' => $glLiabilityBalance,
            'giftCardLiabilityBalance' => round($giftCardGl, 2),
            'storeCreditLiabilityBalance' => round($storeCreditGl, 2),
            'giftCardLiabilityOutstanding' => $giftCardOutstanding,
            'giftCardLiabilityGLBalance' => round($giftCardGl, 2),
            'giftCardLiabilityVariance' => $giftCardVariance,
            'storeCreditLiabilityOutstanding' => $storeCreditOutstanding,
            'storeCreditLiabilityGLBalance' => round($storeCreditGl, 2),
            'storeCreditLiabilityVariance' => $storeCreditVariance,
            'difference' => $difference,
            'status' => $status,
            'issuedAmount' => $issuedAmount,
            'redeemedAmount' => $redeemedAmount,
            'expiredAmount' => $expiredAmount,
            'pendingSettlements' => $pendingSettlements,
            'settledSettlements' => $settledSettlements,
            'pendingGlIssuances' => $pendingGlCount,
        ];
    }

    private function resolveStatus(
        float $totalDifference,
        float $giftCardVariance,
        float $storeCreditVariance,
        int $pendingGlCount,
    ): string {
        if (abs($totalDifference) <= 0.01
            && abs($giftCardVariance) <= 0.01
            && abs($storeCreditVariance) <= 0.01) {
            return 'balanced';
        }

        if ($pendingGlCount > 0) {
            return 'review';
        }

        if (abs($totalDifference) <= 0.01) {
            return 'balanced';
        }

        return abs($giftCardVariance) > 0.01 || abs($storeCreditVariance) > 0.01 ? 'variance' : 'review';
    }
}
