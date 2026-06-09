<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\User;

final class AccountingHealthService
{
    public function __construct(
        private readonly AccountingPostingFailureService $failureService,
        private readonly AccountingAuditService $accountingAuditService,
        private readonly AccountsPayableReconciliationService $apReconciliationService,
        private readonly ProcurementReconciliationService $procurementReconciliationService,
        private readonly PayrollReconciliationService $payrollReconciliationService,
        private readonly \App\Modules\Inventory\Services\InventoryValuationReconciliationService $inventoryValuationReconciliationService,
    ) {}

    /** @return array<string,int|float|string|bool> */
    public function report(?User $actor = null, ?int $outletId = null): array
    {
        $failedPostings = $this->failureService->countPending();
        $pendingPostings = $failedPostings;
        $missingMappings = $this->failureService->countByErrorCode(\App\Models\Modules\Accounting\Domain\AccountingPostingFailure::ERROR_MISSING_MAPPING);
        $unbalancedJournalAttempts = $this->failureService->countByErrorCode(\App\Models\Modules\Accounting\Domain\AccountingPostingFailure::ERROR_UNBALANCED);
        $duplicatePostingAttempts = $this->failureService->countByErrorCode(\App\Models\Modules\Accounting\Domain\AccountingPostingFailure::ERROR_DUPLICATE);

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

        return [
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
        ];
    }
}
