<?php

namespace App\Modules\ShiftClose\Services;

use App\Models\User;
use App\Modules\Orders\Services\PosAuditLogService;

class ShiftCloseAuditService
{
    public function __construct(
        private readonly PosAuditLogService $auditLogService,
    ) {}

    /** @param array<string, mixed> $payload */
    public function started(int $outletId, ?User $user, array $payload = []): void
    {
        $this->auditLogService->log(
            'shift.financial_close_started',
            'shift_close',
            $outletId,
            $outletId,
            $user,
            $payload,
        );
    }

    /** @param array<string, mixed> $payload */
    public function completed(int $outletId, ?User $user, array $payload = []): void
    {
        $this->auditLogService->log(
            'shift.financial_close_completed',
            'shift_close',
            $outletId,
            $outletId,
            $user,
            $payload,
        );
    }

    /** @param array<string, mixed> $payload */
    public function failed(int $outletId, ?User $user, array $payload = []): void
    {
        $this->auditLogService->log(
            'shift.financial_close_failed',
            'shift_close',
            $outletId,
            $outletId,
            $user,
            $payload,
        );
    }

    /** @param array<string, mixed> $payload */
    public function preflightWarning(int $outletId, ?User $user, array $payload = []): void
    {
        $this->auditLogService->log(
            'shift.preflight_warning',
            'shift_close',
            $outletId,
            $outletId,
            $user,
            $payload,
        );
    }

    /** @param array<string, mixed> $payload */
    public function cashReconciliationCompleted(int $outletId, ?User $user, array $payload = []): void
    {
        $this->auditLogService->log(
            'cash.reconciliation_completed',
            'shift_close',
            $outletId,
            $outletId,
            $user,
            $payload,
        );
    }

    /** @param array<string, mixed> $payload */
    public function cashVarianceDetected(int $outletId, ?User $user, array $payload = []): void
    {
        $this->auditLogService->log(
            'cash.variance_detected',
            'shift_close',
            $outletId,
            $outletId,
            $user,
            $payload,
        );
    }
}
