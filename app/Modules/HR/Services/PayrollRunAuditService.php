<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\PayrollRunAudit;
use App\Models\User;
use Illuminate\Support\Collection;

class PayrollRunAuditService
{
    public function record(int $payrollRunId, string $action, ?User $user = null, ?string $notes = null): PayrollRunAudit
    {
        return PayrollRunAudit::query()->create([
            'payroll_run_id' => $payrollRunId,
            'action' => $action,
            'performed_by' => $user?->id,
            'notes' => $notes,
        ]);
    }

    /**
     * @return Collection<int, PayrollRunAudit>
     */
    public function listForRun(int $payrollRunId): Collection
    {
        return PayrollRunAudit::query()
            ->with('performedByUser')
            ->where('payroll_run_id', $payrollRunId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
