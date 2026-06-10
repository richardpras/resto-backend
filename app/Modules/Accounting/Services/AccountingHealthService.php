<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\User;

final class AccountingHealthService
{
    public function __construct(
        private readonly AccountingAuditService $accountingAuditService,
        private readonly AccountsPayableReconciliationService $apReconciliationService,
        private readonly ProcurementReconciliationService $procurementReconciliationService,
        private readonly PayrollReconciliationService $payrollReconciliationService,
        private readonly \App\Modules\Inventory\Services\InventoryValuationReconciliationService $inventoryValuationReconciliationService,
        private readonly \App\Modules\GiftCards\Services\GiftCardReconciliationService $giftCardReconciliationService,
        private readonly AccountingHealthIntelligenceService $intelligenceService,
    ) {}

    /** @return array<string,int|float|string|bool|array<string,mixed>> */
    public function report(?User $actor = null, ?int $outletId = null): array
    {
        $failedPostings = $this->countPendingFailures($outletId);
        $pendingPostings = $failedPostings;
        $missingMappings = $this->countPendingByErrorCode(\App\Models\Modules\Accounting\Domain\AccountingPostingFailure::ERROR_MISSING_MAPPING, $outletId);
        $unbalancedJournalAttempts = $this->countPendingByErrorCode(\App\Models\Modules\Accounting\Domain\AccountingPostingFailure::ERROR_UNBALANCED, $outletId);
        $duplicatePostingAttempts = $this->countPendingByErrorCode(\App\Models\Modules\Accounting\Domain\AccountingPostingFailure::ERROR_DUPLICATE, $outletId);

        $periodQuery = \App\Models\Modules\Accounting\Domain\AccountingPeriod::query();
        if ($outletId !== null && $outletId > 0) {
            $periodQuery->where(function ($q) use ($outletId): void {
                $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
            });
        }
        $openPeriods = (int) (clone $periodQuery)->where('status', 'open')->count();
        $lockedPeriods = (int) (clone $periodQuery)->where('status', 'closed')->count();

        $duplicatePrevented = (int) PosEventLog::query()->where('event_type', 'revenue_duplicate_prevented')->count();

        $apReconciliation = $this->apReconciliationService->report($actor, $outletId);
        $procurementReconciliation = $this->procurementReconciliationService->report($actor, $outletId);
        $payrollReconciliation = $this->payrollReconciliationService->report($actor, $outletId);
        $inventoryValuation = $this->inventoryValuationReconciliationService->report($actor, $outletId);
        $giftCardReconciliation = $this->giftCardReconciliationService->report($actor, $outletId);

        $payrollVariance = 0.0;
        foreach (['payrollExpense', 'salaryPayable', 'pph21Payable', 'bpjsPayable'] as $payrollLine) {
            if (isset($payrollReconciliation[$payrollLine]['difference'])) {
                $payrollVariance = max($payrollVariance, abs((float) $payrollReconciliation[$payrollLine]['difference']));
            }
        }

        $procurementVariance = $this->intelligenceService->maxProcurementVariance($procurementReconciliation);

        $healthScore = max(0, min(100, 100
            - ($failedPostings * 2)
            - ($missingMappings * 3)
            - ($unbalancedJournalAttempts * 5)
        ));

        $this->accountingAuditService->log(
            'health_dashboard_viewed',
            'accounting_health',
            0,
            $outletId,
            $actor,
        );

        $base = [
            'failedPostings' => $failedPostings,
            'pendingPostings' => $pendingPostings,
            'duplicatePostingAttempts' => $duplicatePostingAttempts,
            'unbalancedJournalAttempts' => $unbalancedJournalAttempts,
            'missingMappings' => $missingMappings,
            'openPeriods' => $openPeriods,
            'lockedPeriods' => $lockedPeriods,
            'healthScore' => $healthScore,
            'apReconciliationStatus' => (string) ($apReconciliation['status'] ?? 'unknown'),
            'procurementReconciliationStatus' => (string) ($procurementReconciliation['status'] ?? 'unknown'),
            'payrollReconciliationStatus' => (string) ($payrollReconciliation['status'] ?? 'unknown'),
            'cashFlowAvailable' => true,
            'duplicatePostingPrevented' => $duplicatePrevented,
            'inventoryValuationStatus' => (string) ($inventoryValuation['inventoryValuationStatus'] ?? 'unknown'),
            'inventoryGlBalance' => (float) ($inventoryValuation['inventoryGlBalance'] ?? 0),
            'inventoryValuationBalance' => (float) ($inventoryValuation['inventoryValuationBalance'] ?? 0),
            'inventoryValuationDifference' => (float) ($inventoryValuation['difference'] ?? 0),
            'giftCardReconciliationStatus' => (string) ($giftCardReconciliation['status'] ?? 'unknown'),
            'giftCardLiabilityBalance' => (float) ($giftCardReconciliation['glLiabilityBalance'] ?? 0),
            'giftCardSubledgerOutstanding' => (float) ($giftCardReconciliation['subledgerOutstanding'] ?? 0),
            'giftCardVariance' => (float) ($giftCardReconciliation['difference'] ?? 0),
            'payrollVariance' => $payrollVariance,
            'procurementVariance' => $procurementVariance,
        ];

        return $this->intelligenceService->enrichReport($base, $outletId);
    }

    private function countPendingFailures(?int $outletId): int
    {
        $query = AccountingPostingFailure::query()->where('status', AccountingPostingFailure::STATUS_PENDING);
        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        return (int) $query->count();
    }

    private function countPendingByErrorCode(string $errorCode, ?int $outletId): int
    {
        $query = AccountingPostingFailure::query()
            ->where('error_code', $errorCode)
            ->where('status', AccountingPostingFailure::STATUS_PENDING);

        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        return (int) $query->count();
    }
}
