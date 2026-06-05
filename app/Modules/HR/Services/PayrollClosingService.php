<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\PayrollRunAudit;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class PayrollClosingService
{
    public function __construct(
        private readonly PayrollRunServiceV2 $payrollRuns,
        private readonly PayrollRunAuditService $audits,
    ) {}

    public function startPayment(?User $user, int $runId): PayrollRunV2
    {
        $run = $this->payrollRuns->findAccessible($user, $runId);
        $run->assertNotClosed();
        $this->validateTransition($run->status, PayrollRunV2::STATUS_PROCESSING_PAYMENT);

        $run->update([
            'status' => PayrollRunV2::STATUS_PROCESSING_PAYMENT,
            'payment_status' => PayrollRunV2::PAYMENT_PROCESSING,
        ]);

        $this->audits->record($run->id, PayrollRunAudit::ACTION_PAYMENT_STARTED, $user);

        return $run->refresh()->load(['preparationPeriod', 'items.employee']);
    }

    public function markPaid(?User $user, int $runId, ?string $paidAt = null): PayrollRunV2
    {
        $run = $this->payrollRuns->findAccessible($user, $runId);
        $run->assertNotClosed();
        $this->validateTransition($run->status, PayrollRunV2::STATUS_PAID);

        $paidTimestamp = $paidAt !== null && $paidAt !== ''
            ? Carbon::parse($paidAt)
            : now();

        $run->update([
            'status' => PayrollRunV2::STATUS_PAID,
            'payment_status' => PayrollRunV2::PAYMENT_PAID,
            'paid_at' => $paidTimestamp,
        ]);

        $this->audits->record($run->id, PayrollRunAudit::ACTION_PAYMENT_COMPLETED, $user);

        return $run->refresh()->load(['preparationPeriod', 'items.employee']);
    }

    public function close(?User $user, int $runId, ?string $notes = null): PayrollRunV2
    {
        $run = $this->payrollRuns->findAccessible($user, $runId);
        $this->validateTransition($run->status, PayrollRunV2::STATUS_CLOSED);

        $run->update([
            'status' => PayrollRunV2::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $user?->id,
            'closed_notes' => $notes,
        ]);

        $this->audits->record($run->id, PayrollRunAudit::ACTION_CLOSED, $user, $notes);

        return $run->refresh()->load(['preparationPeriod', 'items.employee']);
    }

    public function reopen(?User $user, int $runId): PayrollRunV2
    {
        $run = $this->payrollRuns->findAccessible($user, $runId);

        if ($run->status !== PayrollRunV2::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'status' => ['Only closed payroll runs can be reopened.'],
            ]);
        }

        $run->update([
            'status' => PayrollRunV2::STATUS_PAID,
            'payment_status' => PayrollRunV2::PAYMENT_PAID,
            'closed_at' => null,
            'closed_by' => null,
            'closed_notes' => null,
        ]);

        $this->audits->record($run->id, PayrollRunAudit::ACTION_REOPENED, $user);

        return $run->refresh()->load(['preparationPeriod', 'items.employee']);
    }

    public function validateTransition(string $currentStatus, string $targetStatus): void
    {
        $allowed = [
            PayrollRunV2::STATUS_FINALIZED => [PayrollRunV2::STATUS_PROCESSING_PAYMENT],
            PayrollRunV2::STATUS_PROCESSING_PAYMENT => [PayrollRunV2::STATUS_PAID],
            PayrollRunV2::STATUS_PAID => [PayrollRunV2::STATUS_CLOSED],
        ];

        if (! in_array($targetStatus, $allowed[$currentStatus] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => [sprintf(
                    'Cannot transition payroll run from %s to %s.',
                    $currentStatus,
                    $targetStatus,
                )],
            ]);
        }
    }
}
