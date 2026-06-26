<?php

namespace Database\Seeders\CustomerDemo\Support;

use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollPayslip;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use App\Models\User;
use App\Modules\HR\Services\AttendancePeriodService;
use App\Modules\HR\Services\PayrollClosingService;
use App\Modules\HR\Services\PayrollPostingService;
use App\Modules\HR\Services\PayrollPreparationPeriodService;
use App\Modules\HR\Services\PayrollPreparationService;
use App\Modules\HR\Services\PayrollRunServiceV2;
use App\Modules\HR\Services\PayslipService;

final class CustomerDemoPayrollBuilder
{
    public function __construct(
        private readonly PayrollPreparationPeriodService $periods,
        private readonly PayrollPreparationService $preparation,
        private readonly AttendancePeriodService $attendancePeriods,
        private readonly PayrollRunServiceV2 $runs,
        private readonly PayrollClosingService $closing,
        private readonly PayrollPostingService $posting,
        private readonly PayslipService $payslips,
    ) {}

    /** @param list<Employee> $employees */
    public function buildPostedRun(User $actor, int $outletId, string $periodStart, string $periodEnd, array $employees): PayrollRunV2
    {
        $period = PayrollPreparationPeriod::query()
            ->where('outlet_id', $outletId)
            ->where('period_start', $periodStart)
            ->first();

        if ($period === null) {
            $period = $this->periods->create($actor, [
                'outletId' => $outletId,
                'periodStart' => $periodStart,
                'periodEnd' => $periodEnd,
            ]);
        }

        $attendance = $period->attendancePeriodLock;
        if ($attendance !== null && $attendance->status === AttendancePeriodLock::STATUS_DRAFT) {
            $this->attendancePeriods->approve($actor, (int) $attendance->id);
            $attendance->refresh();
        }

        if ($period->generated_at === null) {
            $this->periods->generate($actor, (int) $period->id);
            $period->refresh();
        }

        foreach ($employees as $employee) {
            PayrollPreparationSnapshot::query()->updateOrCreate(
                ['preparation_period_id' => $period->id, 'employee_id' => $employee->id],
                ['review_required' => false, 'attended_days' => 22, 'overtime_hours' => $employee->position === 'Cook' ? 8 : 0],
            );
        }

        if ($period->status === PayrollPreparationPeriod::STATUS_DRAFT) {
            $this->periods->approve($actor, (int) $period->id);
            $period->refresh();
        }

        if ($period->status === PayrollPreparationPeriod::STATUS_APPROVED) {
            $this->periods->lock($actor, (int) $period->id);
            $period->refresh();
        }

        $run = PayrollRunV2::query()
            ->where('payroll_preparation_period_id', $period->id)
            ->first();

        if ($run === null) {
            $run = $this->runs->create($actor, ['payrollPreparationPeriodId' => (int) $period->id]);
        }

        if (in_array($run->status, [PayrollRunV2::STATUS_DRAFT, PayrollRunV2::STATUS_CALCULATED], true)) {
            $run = $this->runs->calculate($actor, (int) $run->id);
        }

        if ($run->status === PayrollRunV2::STATUS_CALCULATED) {
            $run = $this->runs->approve($actor, (int) $run->id);
        }

        if ($run->status === PayrollRunV2::STATUS_APPROVED) {
            $run = $this->runs->finalize($actor, (int) $run->id);
        }

        if ($run->status === PayrollRunV2::STATUS_FINALIZED) {
            $run = $this->closing->startPayment($actor, (int) $run->id);
        }

        if ($run->status === PayrollRunV2::STATUS_PROCESSING_PAYMENT) {
            $run = $this->closing->markPaid($actor, (int) $run->id, $periodEnd);
        }

        if ($run->status === PayrollRunV2::STATUS_PAID) {
            $this->payslips->generateForRun($actor, (int) $run->id);
            $this->payslips->renderPendingBatch((int) $run->id, $outletId, 50);
            $run = $this->closing->close($actor, (int) $run->id, 'WR WB demo payroll Mei 2026');
        }

        if ($run->status === PayrollRunV2::STATUS_CLOSED) {
            $this->ensureDemoPayslips($actor, $run, $outletId);

            try {
                $this->posting->post($actor, (int) $run->id);
            } catch (\Illuminate\Validation\ValidationException $e) {
                // Idempotent re-run when already posted.
            }
        }

        return $run->refresh();
    }

    private function ensureDemoPayslips(User $actor, PayrollRunV2 $run, int $outletId): void
    {
        $run->loadCount('items');

        if ($run->items_count === 0) {
            return;
        }

        $existingCount = PayrollPayslip::query()
            ->where('payroll_run_id', $run->id)
            ->count();

        if ($existingCount > 0) {
            return;
        }

        $this->payslips->ensurePayslipsForDemoSeed($actor, (int) $run->id);
        $this->payslips->renderPendingBatch((int) $run->id, $outletId, 50);
    }
}
