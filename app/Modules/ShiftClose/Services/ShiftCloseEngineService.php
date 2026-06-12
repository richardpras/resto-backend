<?php

namespace App\Modules\ShiftClose\Services;

use App\Models\Modules\ShiftClose\Domain\ShiftCloseRun;
use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Validation\ValidationException;

class ShiftCloseEngineService
{
    public function __construct(
        private readonly ShiftClosePreflightService $preflightService,
        private readonly ShiftCloseInventoryProcessor $inventoryProcessor,
        private readonly ShiftCloseAccountingProcessor $accountingProcessor,
        private readonly ShiftCloseCashReconciliationService $cashReconciliationService,
        private readonly ShiftCloseAuditService $auditService,
        private readonly ShiftCloseNotificationAdapter $notificationAdapter,
        private readonly ShiftCloseLockService $lockService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(
        ?int $tenantId,
        int $outletId,
        ?User $user = null,
        bool $confirm = false,
        bool $force = false,
        ?float $actualCash = null,
        ?int $posSessionId = null,
    ): array {
        $shiftDate = $this->lockService->shiftDate();
        $lock = $this->lockService->acquire($outletId, $shiftDate);

        try {
            return $this->executeRun($tenantId, $outletId, $user, $confirm, $force, $actualCash, $posSessionId, $shiftDate);
        } finally {
            $this->lockService->release($lock);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function executeRun(
        ?int $tenantId,
        int $outletId,
        ?User $user,
        bool $confirm,
        bool $force,
        ?float $actualCash,
        ?int $posSessionId,
        string $shiftDate,
    ): array {
        $preflight = $this->preflightService->evaluate($user, $outletId, $tenantId, $posSessionId);
        $this->assertCanProceed($preflight, $user, $confirm, $force);

        if (($preflight['warnings'] ?? []) !== []) {
            $this->auditService->preflightWarning($outletId, $user, [
                'warnings' => $preflight['warnings'],
                'checks' => $preflight['checks'],
                'forced' => $force,
            ]);
            if ($force || $confirm) {
                $this->notificationAdapter->notifyRequiresReview(
                    $outletId,
                    'Shift close completed with preflight warnings.',
                    ['warnings' => $preflight['warnings'], 'checks' => $preflight['checks']],
                );
            }
        }

        $qr = is_array($preflight['qrOrders'] ?? null) ? $preflight['qrOrders'] : [];
        $openSessions = is_array($preflight['openPosSessions'] ?? null) ? $preflight['openPosSessions'] : [];

        $run = ShiftCloseRun::query()->create([
            'tenant_id' => (int) ($tenantId ?? 1),
            'outlet_id' => $outletId,
            'shift_date' => $shiftDate,
            'pos_session_id' => $posSessionId,
            'run_by_user_id' => $user?->id,
            'created_by_user_id' => $user?->id,
            'status' => ShiftCloseRun::STATUS_RUNNING,
            'severity' => $preflight['severity'] ?? null,
            'ready' => (bool) ($preflight['ready'] ?? false),
            'preflight_snapshot' => $preflight,
            'open_bill_count' => (int) ($preflight['checks']['openBills'] ?? 0),
            'open_pos_session_count' => (int) ($openSessions['count'] ?? 0),
            'pending_qr_count' => (int) ($qr['pending'] ?? 0),
            'under_review_qr_count' => (int) ($qr['underReview'] ?? 0),
            'linked_unpaid_qr_bill_count' => (int) ($qr['linkedUnpaidBills'] ?? 0),
            'pending_consumption_count' => (int) ($preflight['checks']['pendingConsumption'] ?? 0),
            'failed_accounting_posting_count' => (int) ($preflight['checks']['failedAccountingPostings'] ?? 0),
            'started_at' => now(),
        ]);

        $this->auditService->started($outletId, $user, [
            'runId' => $run->id,
            'preflight' => $preflight,
            'force' => $force,
        ]);

        try {
            $inventory = $this->inventoryProcessor->process($outletId);
            $accounting = $this->accountingProcessor->process(
                $tenantId,
                $outletId,
                (float) ($inventory['totalCogs'] ?? 0.0),
            );
            $cash = $this->cashReconciliationService->reconcile($outletId, $actualCash, $posSessionId);

            $this->auditService->cashReconciliationCompleted($outletId, $user, $cash);

            if (($cash['variance'] ?? null) !== null && ($cash['status'] ?? '') !== 'balanced') {
                $this->auditService->cashVarianceDetected($outletId, $user, $cash);
                $this->notificationAdapter->notifyCashVariance(
                    $outletId,
                    (float) $cash['variance'],
                    $cash,
                );
            }

            $hasWarnings = ($preflight['warnings'] ?? []) !== [];
            $finalStatus = $hasWarnings
                ? ShiftCloseRun::STATUS_COMPLETED_WITH_WARNINGS
                : ShiftCloseRun::STATUS_COMPLETED;

            $result = [
                'runId' => $run->id,
                'status' => $finalStatus,
                'preflight' => $preflight,
                'inventory' => $inventory,
                'accounting' => $accounting,
                'cash' => $cash,
                'orderCount' => (int) ($accounting['orderCount'] ?? 0),
                'totalSales' => (float) ($accounting['totalSales'] ?? $cash['totalSales'] ?? 0.0),
                'totalCogs' => (float) ($accounting['totalCogs'] ?? 0.0),
                'journalId' => $accounting['journalId'] ?? null,
                'skipped' => (bool) ($accounting['skipped'] ?? false),
                'reason' => $accounting['reason'] ?? null,
                'forced' => $force,
                'reportPath' => "/api/v1/shift-close/{$run->id}/report?outletId={$outletId}",
                'inventoryConsumption' => [
                    'processed' => (int) ($inventory['processed'] ?? 0),
                    'reviewRequired' => (int) ($inventory['reviewRequired'] ?? 0),
                    'failed' => (int) ($inventory['failed'] ?? 0),
                    'totalCogs' => (float) ($inventory['totalCogs'] ?? 0.0),
                ],
            ];

            $run->update([
                'status' => $finalStatus,
                'result_snapshot' => $result,
                'sales_amount' => (float) ($cash['totalSales'] ?? $result['totalSales']),
                'cash_sales' => (float) ($cash['cashSales'] ?? 0),
                'non_cash_sales' => (float) ($cash['nonCashSales'] ?? 0),
                'opening_cash' => (float) ($cash['openingCash'] ?? 0),
                'cash_refunds' => (float) ($cash['cashRefunds'] ?? 0),
                'cash_expenses' => (float) ($cash['cashExpenses'] ?? 0),
                'cash_in' => (float) ($cash['cashIn'] ?? 0),
                'cash_out' => (float) ($cash['cashOut'] ?? 0),
                'cash_expected' => $cash['expected'] ?? null,
                'cash_actual' => $cash['actual'] ?? null,
                'cash_variance' => $cash['variance'] ?? null,
                'expected_cash' => $cash['expected'] ?? null,
                'actual_cash' => $cash['actual'] ?? null,
                'inventory_variance' => (int) ($inventory['varianceDetected'] ?? 0),
                'metadata' => [
                    'paymentBreakdown' => $cash['paymentBreakdown'] ?? [],
                    'limitations' => $cash['limitations'] ?? [],
                    'forced' => $force,
                ],
                'completed_at' => now(),
            ]);

            $this->auditService->completed($outletId, $user, [
                'runId' => $run->id,
                'orderCount' => $result['orderCount'],
                'journalId' => $result['journalId'],
                'status' => $finalStatus,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $run->update([
                'status' => ShiftCloseRun::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $this->auditService->failed($outletId, $user, [
                'runId' => $run->id,
                'error' => $e->getMessage(),
            ]);
            $this->notificationAdapter->notifyCloseFailed($outletId, $e->getMessage(), [
                'runId' => $run->id,
            ]);

            throw $e;
        }
    }

    /** @param array<string, mixed> $preflight */
    private function assertCanProceed(array $preflight, ?User $user, bool $confirm, bool $force): void
    {
        $blocks = $preflight['blocks'] ?? [];
        $canElevate = $user !== null && $user->hasPermission('settings.manage');

        if ($blocks !== [] && ! ($force && $canElevate)) {
            throw ValidationException::withMessages([
                'preflight' => ['Shift close blocked by preflight checks. Resolve issues or use elevated force close.'],
                'checks' => $preflight['checks'] ?? [],
                'blocks' => $blocks,
            ]);
        }

        $needsAck = ($preflight['severity'] ?? 'healthy') === 'warning';
        if ($needsAck && ! $confirm && ! $force) {
            throw ValidationException::withMessages([
                'confirm' => ['Preflight warnings detected. Set confirm=true or force=true to proceed.'],
                'preflight' => $preflight,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function readiness(?User $user, int $outletId, ?int $tenantId = null): array
    {
        $preflight = $this->preflightService->evaluate($user, $outletId, $tenantId);
        $qr = is_array($preflight['qrOrders'] ?? null) ? $preflight['qrOrders'] : [];
        $openSessions = is_array($preflight['openPosSessions'] ?? null) ? $preflight['openPosSessions'] : [];

        $lastRun = ShiftCloseRun::query()
            ->where('outlet_id', $outletId)
            ->whereIn('status', [
                ShiftCloseRun::STATUS_COMPLETED,
                ShiftCloseRun::STATUS_COMPLETED_WITH_WARNINGS,
            ])
            ->latest('completed_at')
            ->first();

        $running = ShiftCloseRun::query()
            ->where('outlet_id', $outletId)
            ->where('shift_date', $this->lockService->shiftDate())
            ->where('status', ShiftCloseRun::STATUS_RUNNING)
            ->exists();

        return [
            'label' => 'Shift Close Readiness',
            'ready' => (bool) ($preflight['ready'] ?? false) && ! $running,
            'severity' => $preflight['severity'] ?? 'healthy',
            'checks' => $preflight['checks'] ?? [],
            'openPosSessions' => $openSessions,
            'qrOrders' => $qr,
            'warnings' => $preflight['warnings'] ?? [],
            'blocks' => $preflight['blocks'] ?? [],
            'lastRunStatus' => $lastRun?->status,
            'closeRunning' => $running,
            'lastClose' => $lastRun !== null ? [
                'runId' => $lastRun->id,
                'completedAt' => $lastRun->completed_at?->toISOString(),
                'status' => $lastRun->status,
                'openBillCount' => (int) ($lastRun->open_bill_count ?? $preflight['checks']['openBills'] ?? 0),
                'openPosSessionCount' => (int) ($lastRun->open_pos_session_count ?? 0),
                'pendingQr' => (int) ($lastRun->pending_qr_count ?? 0) + (int) ($lastRun->under_review_qr_count ?? 0),
                'cashVariance' => $lastRun->cash_variance,
                'inventoryVariance' => (int) ($lastRun->inventory_variance ?? 0),
                'postingStatus' => in_array($lastRun->status, [ShiftCloseRun::STATUS_COMPLETED, ShiftCloseRun::STATUS_COMPLETED_WITH_WARNINGS], true)
                    ? 'posted'
                    : $lastRun->status,
                'journalId' => is_array($lastRun->result_snapshot)
                    ? ($lastRun->result_snapshot['journalId'] ?? null)
                    : null,
            ] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function history(int $outletId, int $limit = 20): array
    {
        return ShiftCloseRun::query()
            ->where('outlet_id', $outletId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ShiftCloseRun $run): array => [
                'id' => $run->id,
                'shiftDate' => $run->shift_date?->toDateString(),
                'status' => $run->status,
                'severity' => $run->severity,
                'ready' => $run->ready,
                'salesAmount' => $run->sales_amount,
                'cashSales' => $run->cash_sales,
                'nonCashSales' => $run->non_cash_sales,
                'cashExpected' => $run->expected_cash ?? $run->cash_expected,
                'cashActual' => $run->actual_cash ?? $run->cash_actual,
                'cashVariance' => $run->cash_variance,
                'inventoryVariance' => $run->inventory_variance,
                'openBillCount' => $run->open_bill_count,
                'openPosSessionCount' => $run->open_pos_session_count,
                'pendingQrCount' => $run->pending_qr_count,
                'underReviewQrCount' => $run->under_review_qr_count,
                'linkedUnpaidQrBillCount' => $run->linked_unpaid_qr_bill_count,
                'startedAt' => $run->started_at?->toISOString(),
                'completedAt' => $run->completed_at?->toISOString(),
                'failureReason' => $run->failure_reason,
            ])
            ->all();
    }
}
