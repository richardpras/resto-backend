<?php

namespace App\Modules\Accounting\Services;

use Carbon\Carbon;

final class CashFlowStatementService
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly GlBalanceService $glBalanceService,
    ) {}

    /** @return array{operating: array<string,float>, investing: array<string,float>, financing: array<string,float>, netCashChange: float, period: string} */
    public function buildReport(?string $from, ?string $to, ?int $outletId = null, ?int $tenantId = null, string $period = 'monthly'): array
    {
        $fromDate = $from !== null && $from !== '' ? Carbon::parse($from)->toDateString() : now()->startOfMonth()->toDateString();
        $toDate = $to !== null && $to !== '' ? Carbon::parse($to)->toDateString() : now()->toDateString();

        $pl = $this->accountingService->buildProfitLossReport($fromDate, $toDate, $outletId, $tenantId);
        $netProfit = (float) $pl['netProfit'];

        $priorTo = Carbon::parse($fromDate)->subDay()->toDateString();

        $arChange = $this->balanceDelta('1200', 'asset', $outletId, $priorTo, $toDate);
        $apChange = $this->balanceDelta('2100', 'liability', $outletId, $priorTo, $toDate);
        $inventoryChange = $this->balanceDelta('1300', 'asset', $outletId, $priorTo, $toDate);

        $operating = [
            'netProfit' => round($netProfit, 2),
            'depreciation' => 0.0,
            'accountsReceivableChange' => round(-$arChange, 2),
            'accountsPayableChange' => round($apChange, 2),
            'inventoryChange' => round(-$inventoryChange, 2),
        ];
        $operatingTotal = array_sum($operating);

        $fixedAssetChange = $this->subtypeBalanceDelta('fixed_asset', 'asset', $outletId, $priorTo, $toDate);
        $investing = [
            'assetPurchases' => round(min(0, -$fixedAssetChange), 2),
            'assetDisposals' => round(max(0, $fixedAssetChange), 2),
        ];
        $investingTotal = array_sum($investing);

        $equityChange = $this->subtypeBalanceDelta('equity', 'equity', $outletId, $priorTo, $toDate);
        $longTermLoanChange = $this->balanceDelta('2500', 'liability', $outletId, $priorTo, $toDate);
        $financing = [
            'loans' => round($longTermLoanChange, 2),
            'capitalInjection' => round(max(0, $equityChange), 2),
            'ownerDrawings' => round(min(0, -$equityChange), 2),
        ];
        $financingTotal = array_sum($financing);

        return [
            'operating' => $operating + ['total' => round($operatingTotal, 2)],
            'investing' => $investing + ['total' => round($investingTotal, 2)],
            'financing' => $financing + ['total' => round($financingTotal, 2)],
            'netCashChange' => round($operatingTotal + $investingTotal + $financingTotal, 2),
            'period' => $period,
            'from' => $fromDate,
            'to' => $toDate,
        ];
    }

    /** @param list<string> $types */
    private function balanceDelta(string $code, string $accountType, ?int $outletId, string $priorTo, string $to): float
    {
        $opening = $this->glBalanceService->codeBalance($code, $accountType, $outletId, $priorTo);
        $closing = $this->glBalanceService->codeBalance($code, $accountType, $outletId, $to);

        return round($closing - $opening, 2);
    }

    private function subtypeBalanceDelta(string $subtype, string $accountType, ?int $outletId, string $priorTo, string $to): float
    {
        $opening = $this->glBalanceService->subtypeBalance($subtype, $accountType, $outletId, $priorTo);
        $closing = $this->glBalanceService->subtypeBalance($subtype, $accountType, $outletId, $to);

        return round($closing - $opening, 2);
    }
}
