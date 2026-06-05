<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use App\Models\User;

class PayrollClosingAnalyticsService
{
    public function __construct(
        private readonly PayrollRunServiceV2 $payrollRuns,
        private readonly PayrollRunAuditService $audits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function closingSummary(?User $user, int $runId): array
    {
        $run = $this->payrollRuns->findAccessible($user, $runId);
        $totals = $this->aggregateTotals((int) $run->id);

        return [
            'run' => [
                'id' => (int) $run->id,
                'status' => $run->status,
                'paymentStatus' => $run->payment_status,
                'closedStatus' => $run->isClosed() ? 'closed' : 'open',
                'paidAt' => $run->paid_at?->toIso8601String(),
                'closedAt' => $run->closed_at?->toIso8601String(),
            ],
            'totals' => $totals,
            'auditTrail' => $this->audits->listForRun((int) $run->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aggregateTotals(int $runId): array
    {
        $rows = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->get();

        $totalBpjsEmployee = round(
            (float) $rows->sum('bpjs_kesehatan_employee')
            + (float) $rows->sum('bpjs_jht_employee')
            + (float) $rows->sum('bpjs_jp_employee'),
            2,
        );

        $totalBpjsEmployer = round(
            (float) $rows->sum('bpjs_kesehatan_company')
            + (float) $rows->sum('bpjs_jht_company')
            + (float) $rows->sum('bpjs_jp_company')
            + (float) $rows->sum('bpjs_jkk_company')
            + (float) $rows->sum('bpjs_jkm_company'),
            2,
        );

        return [
            'employeeCount' => $rows->count(),
            'grossPayroll' => round((float) $rows->sum('gross_salary'), 2),
            'netPayroll' => round((float) $rows->sum('net_salary'), 2),
            'totalBPJS' => round($totalBpjsEmployee + $totalBpjsEmployer, 2),
            'totalBpjsEmployee' => $totalBpjsEmployee,
            'totalBpjsEmployer' => $totalBpjsEmployer,
            'totalPPh21' => round((float) $rows->sum('pph21_amount'), 2),
            'totalLoans' => round((float) $rows->sum('loan_deduction'), 2),
            'totalCashAdvance' => round((float) $rows->sum('cash_advance_deduction'), 2),
            'totalReimbursement' => round((float) $rows->sum('reimbursement_earning'), 2),
            'totalAdjustments' => round(
                (float) $rows->sum('adjustment_earning') - (float) $rows->sum('adjustment_deduction'),
                2,
            ),
            'totalAdjustmentEarning' => round((float) $rows->sum('adjustment_earning'), 2),
            'totalAdjustmentDeduction' => round((float) $rows->sum('adjustment_deduction'), 2),
            'paymentStatus' => PayrollRunV2::query()->whereKey($runId)->value('payment_status'),
            'closedStatus' => PayrollRunV2::query()->whereKey($runId)->value('status') === PayrollRunV2::STATUS_CLOSED
                ? 'closed'
                : 'open',
        ];
    }
}
