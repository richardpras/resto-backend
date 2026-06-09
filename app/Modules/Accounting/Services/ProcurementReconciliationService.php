<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Purchase\Domain\ProcurementPosting;
use App\Models\User;
use App\Modules\Purchase\Services\PurchaseScopeService;
use Illuminate\Support\Facades\DB;

final class ProcurementReconciliationService
{
    public function __construct(
        private readonly GlBalanceService $glBalanceService,
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    /** @return array<string, mixed> */
    public function report(?User $actor, ?int $outletId): array
    {
        $query = ProcurementPosting::query()->where('status', ProcurementPosting::STATUS_POSTED);
        $this->purchaseScopeService->applyOutletScope($query, $actor, $outletId);

        $grnSubledger = (float) (clone $query)->where('source_type', ProcurementPosting::SOURCE_GRN)->sum('amount');
        $invoiceSubledger = (float) (clone $query)->where('source_type', ProcurementPosting::SOURCE_INVOICE)->sum('amount');
        $paymentSubledger = (float) (clone $query)->where('source_type', ProcurementPosting::SOURCE_SUPPLIER_PAYMENT)->sum('amount');

        $inventoryGl = $this->glBalanceService->categoryBalance('inventory', ['1300'], ['asset'], $outletId);
        $grniGl = $this->glBalanceService->categoryBalance('grni', ['2140', '2115'], ['liability'], $outletId);
        $apGl = $this->glBalanceService->categoryBalance('accounts_payable', ['2100'], ['liability'], $outletId);

        $grnVariance = round($grnSubledger - ($inventoryGl > 0 ? min($inventoryGl, $grnSubledger) : 0), 2);

        return [
            'grni' => $this->line($grniGl, $invoiceSubledger),
            'inventory' => $this->line($inventoryGl, $grnSubledger),
            'accountsPayable' => $this->line($apGl, $invoiceSubledger - $paymentSubledger),
            'postedGrnTotal' => round($grnSubledger, 2),
            'postedInvoiceTotal' => round($invoiceSubledger, 2),
            'postedPaymentTotal' => round($paymentSubledger, 2),
            'status' => abs($grnVariance) <= 0.01 ? 'balanced' : 'review',
        ];
    }

    /** @return array{glBalance: float, subledger: float, difference: float, status: string} */
    private function line(float $glBalance, float $subledger): array
    {
        $difference = round($glBalance - $subledger, 2);

        return [
            'glBalance' => round($glBalance, 2),
            'subledger' => round($subledger, 2),
            'difference' => $difference,
            'status' => abs($difference) <= 0.01 ? 'balanced' : 'variance',
        ];
    }
}
