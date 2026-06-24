<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\User;
use App\Modules\Purchase\Services\PurchaseScopeService;

final class AccountsPayableReconciliationService
{
    public function __construct(
        private readonly GlBalanceService $glBalanceService,
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    /** @return array{subledger: float, glBalance: float, difference: float, status: string} */
    public function report(?User $actor, ?int $outletId): array
    {
        $query = PurchaseInvoice::query()
            ->whereIn('status', ['approved', 'partially_paid', 'paid']);

        $this->purchaseScopeService->applyOutletScope($query, $actor, $outletId);

        $subledger = 0.0;
        foreach ($query->get(['total_amount', 'paid_amount']) as $invoice) {
            $subledger += max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount);
        }
        $subledger = round($subledger, 2);

        $glBalance = $this->glBalanceService->mappedRuleBalance(
            null,
            $outletId,
            'procurement',
            'procurement.invoice.accounts_payable',
        );

        $difference = round($subledger - $glBalance, 2);

        return [
            'subledger' => $subledger,
            'glBalance' => $glBalance,
            'difference' => $difference,
            'status' => abs($difference) <= 0.01 ? 'balanced' : 'variance',
        ];
    }
}
