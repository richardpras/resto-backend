<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use Illuminate\Support\Collection;

/**
 * Salary calculation from locked preparation snapshots — no tax or accounting.
 */
class PayrollCalculationService
{
    private const MONTHLY_HOURS_DIVISOR = 173;

    private const DAILY_SALARY_DIVISOR = 30;

    public function __construct(
        private readonly LoanDeductionService $loanDeductions,
        private readonly EmployeeLoanService $employeeLoans,
        private readonly CashAdvanceDeductionService $cashAdvanceDeductions,
        private readonly CashAdvanceService $cashAdvances,
        private readonly PayrollAdjustmentService $payrollAdjustments,
        private readonly BpjsCalculationService $bpjsCalculation,
    ) {}

    /**
     * @return Collection<int, PayrollRunItemV2>
     */
    public function calculateRun(PayrollRunV2 $run): Collection
    {
        $period = $run->preparationPeriod;
        abort_if($period === null, 422, 'Preparation period not found for this run.');

        $periodStart = $period->period_start->toDateString();
        $periodEnd = $period->period_end->toDateString();

        $this->employeeLoans->resetDeductionsForPayrollRun((int) $run->id);
        $this->cashAdvances->resetDeductionsForPayrollRun((int) $run->id);

        $snapshots = PayrollPreparationSnapshot::query()
            ->with('employee')
            ->where('preparation_period_id', $period->id)
            ->get();

        $profiles = EmployeeSalaryProfile::query()
            ->whereIn('employee_id', $snapshots->pluck('employee_id'))
            ->get()
            ->keyBy('employee_id');

        $items = collect();

        foreach ($snapshots as $snapshot) {
            $employeeId = (int) $snapshot->employee_id;
            $profile = $profiles->get($employeeId);
            $line = $this->buildLineItem($snapshot, $profile, 0, 0, 0, 0, 0, 0, $periodStart, $periodEnd);

            $item = PayrollRunItemV2::query()->updateOrCreate(
                [
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employeeId,
                ],
                $line,
            );

            $loan = $this->employeeLoans->applyPayrollDeductions(
                (int) $run->id,
                (int) $item->id,
                $employeeId,
                $periodStart,
                $periodEnd,
                $this->loanDeductions,
            );

            $cash = $this->cashAdvances->applyPayrollDeductions(
                (int) $item->id,
                $employeeId,
                $periodStart,
                $periodEnd,
                $this->cashAdvanceDeductions,
            );

            $adjustments = $this->payrollAdjustments->totalsForEmployeeInPeriod($employeeId, $periodStart, $periodEnd);

            $line = $this->buildLineItem(
                $snapshot,
                $profile,
                $loan['loanDeduction'],
                $loan['remainingBalance'],
                $cash['cashAdvanceDeduction'],
                $cash['remainingBalance'],
                $adjustments['adjustmentEarning'],
                $adjustments['adjustmentDeduction'],
                $periodStart,
                $periodEnd,
            );
            $item->update($line);

            $items->push($item->refresh());
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildLineItem(
        PayrollPreparationSnapshot $snapshot,
        ?EmployeeSalaryProfile $profile,
        float $loanDeduction = 0,
        float $remainingLoanBalance = 0,
        float $cashAdvanceDeduction = 0,
        float $remainingCashAdvanceBalance = 0,
        float $adjustmentEarning = 0,
        float $adjustmentDeduction = 0,
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): array {
        $employeeId = (int) $snapshot->employee_id;

        if ($periodStart !== null && $periodEnd !== null && $adjustmentEarning === 0.0 && $adjustmentDeduction === 0.0) {
            $totals = $this->payrollAdjustments->totalsForEmployeeInPeriod($employeeId, $periodStart, $periodEnd);
            $adjustmentEarning = $totals['adjustmentEarning'];
            $adjustmentDeduction = $totals['adjustmentDeduction'];
        }

        $basic = round((float) ($profile?->basic_salary ?? $snapshot->employee?->base_salary ?? 0), 2);
        $allowance = round((float) ($profile?->default_allowance ?? 0), 2);
        $defaultDeduction = round((float) ($profile?->default_deduction ?? 0), 2);

        $overtimeHours = round((float) $snapshot->overtime_hours, 2);
        $overtimePay = $this->calculateOvertimePay($basic, $overtimeHours, $profile);

        $unpaidLeaveDays = round((float) $snapshot->unpaid_leave_days, 2);
        $unpaidLeaveDeduction = $this->calculateUnpaidLeaveDeduction($basic, $unpaidLeaveDays, $profile);

        $absentDays = (int) $snapshot->absent_days;
        $attendanceDeduction = $this->calculateAttendanceDeduction($absentDays, $profile);

        $loanDeduction = round($loanDeduction, 2);
        $remainingLoanBalance = round($remainingLoanBalance, 2);
        $cashAdvanceDeduction = round($cashAdvanceDeduction, 2);
        $remainingCashAdvanceBalance = round($remainingCashAdvanceBalance, 2);
        $adjustmentEarning = round($adjustmentEarning, 2);
        $adjustmentDeduction = round($adjustmentDeduction, 2);

        $bpjs = $periodEnd !== null
            ? $this->bpjsCalculation->calculateForEmployee($employeeId, $basic + $allowance, $periodEnd)
            : $this->zeroBpjs();
        $bpjsEmployeeDeduction = $this->bpjsCalculation->employeeDeductionTotal($bpjs);

        $gross = round($basic + $allowance + $overtimePay + $adjustmentEarning, 2);
        $totalDeductions = round(
            $defaultDeduction + $unpaidLeaveDeduction + $attendanceDeduction + $loanDeduction
            + $cashAdvanceDeduction + $adjustmentDeduction + $bpjsEmployeeDeduction,
            2,
        );
        $net = round($gross - $totalDeductions, 2);

        $calculationJson = [
            'basicSalary' => $basic,
            'allowance' => $allowance,
            'overtimeHours' => $overtimeHours,
            'overtimePay' => $overtimePay,
            'unpaidLeaveDays' => $unpaidLeaveDays,
            'unpaidLeaveDeduction' => $unpaidLeaveDeduction,
            'absentDays' => $absentDays,
            'attendanceDeduction' => $attendanceDeduction,
            'loanDeduction' => $loanDeduction,
            'remainingLoanBalance' => $remainingLoanBalance,
            'cashAdvanceDeduction' => $cashAdvanceDeduction,
            'remainingCashAdvanceBalance' => $remainingCashAdvanceBalance,
            'adjustmentEarning' => $adjustmentEarning,
            'adjustmentDeduction' => $adjustmentDeduction,
            'defaultDeduction' => $defaultDeduction,
            'bpjs' => $bpjs,
            'bpjsEmployeeDeductionTotal' => $bpjsEmployeeDeduction,
            'grossSalary' => $gross,
            'totalDeductions' => $totalDeductions,
            'netSalary' => $net,
            'inputs' => [
                'attendanceDays' => (int) $snapshot->attended_days,
                'leaveDays' => (float) $snapshot->leave_days,
                'scheduledDays' => (int) $snapshot->scheduled_days,
            ],
        ];

        return [
            'basic_salary' => $basic,
            'attendance_days' => (int) $snapshot->attended_days,
            'absent_days' => $absentDays,
            'leave_days' => (float) $snapshot->leave_days,
            'unpaid_leave_days' => $unpaidLeaveDays,
            'overtime_hours' => $overtimeHours,
            'overtime_pay' => $overtimePay,
            'unpaid_leave_deduction' => $unpaidLeaveDeduction,
            'attendance_deduction' => $attendanceDeduction,
            'loan_deduction' => $loanDeduction,
            'remaining_loan_balance' => $remainingLoanBalance,
            'cash_advance_deduction' => $cashAdvanceDeduction,
            'remaining_cash_advance_balance' => $remainingCashAdvanceBalance,
            'adjustment_earning' => $adjustmentEarning,
            'adjustment_deduction' => $adjustmentDeduction,
            'bpjs_kesehatan_employee' => $bpjs['bpjs_kesehatan_employee'],
            'bpjs_kesehatan_company' => $bpjs['bpjs_kesehatan_company'],
            'bpjs_jht_employee' => $bpjs['bpjs_jht_employee'],
            'bpjs_jht_company' => $bpjs['bpjs_jht_company'],
            'bpjs_jp_employee' => $bpjs['bpjs_jp_employee'],
            'bpjs_jp_company' => $bpjs['bpjs_jp_company'],
            'bpjs_jkk_company' => $bpjs['bpjs_jkk_company'],
            'bpjs_jkm_company' => $bpjs['bpjs_jkm_company'],
            'gross_salary' => $gross,
            'total_deductions' => $totalDeductions,
            'net_salary' => $net,
            'calculation_json' => $calculationJson,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function zeroBpjs(): array
    {
        return [
            'bpjs_kesehatan_employee' => 0.0,
            'bpjs_kesehatan_company' => 0.0,
            'bpjs_jht_employee' => 0.0,
            'bpjs_jht_company' => 0.0,
            'bpjs_jp_employee' => 0.0,
            'bpjs_jp_company' => 0.0,
            'bpjs_jkk_company' => 0.0,
            'bpjs_jkm_company' => 0.0,
        ];
    }

    public function calculateOvertimePay(float $basicSalary, float $overtimeHours, ?EmployeeSalaryProfile $profile): float
    {
        if ($overtimeHours <= 0) {
            return 0.0;
        }

        $rateType = $profile?->overtime_rate_type ?? EmployeeSalaryProfile::OVERTIME_RATE_FIXED_HOURLY;
        $rateValue = (float) ($profile?->overtime_rate_value ?? 0);

        if ($rateValue <= 0) {
            return 0.0;
        }

        if ($rateType === EmployeeSalaryProfile::OVERTIME_RATE_MULTIPLIER_HOURLY) {
            $hourlySalary = $basicSalary / self::MONTHLY_HOURS_DIVISOR;

            return round($overtimeHours * $hourlySalary * $rateValue, 2);
        }

        return round($overtimeHours * $rateValue, 2);
    }

    public function calculateUnpaidLeaveDeduction(float $basicSalary, float $unpaidLeaveDays, ?EmployeeSalaryProfile $profile): float
    {
        if ($unpaidLeaveDays <= 0) {
            return 0.0;
        }

        $enabled = $profile?->unpaid_leave_deduction_enabled ?? true;
        if (! $enabled) {
            return 0.0;
        }

        $dailySalary = $basicSalary / self::DAILY_SALARY_DIVISOR;

        return round($unpaidLeaveDays * $dailySalary, 2);
    }

    public function calculateAttendanceDeduction(int $absentDays, ?EmployeeSalaryProfile $profile): float
    {
        if ($absentDays <= 0 || $profile === null) {
            return 0.0;
        }

        if (! $profile->attendance_deduction_enabled) {
            return 0.0;
        }

        $perDay = (float) ($profile?->attendance_deduction_per_day ?? 0);
        if ($perDay <= 0) {
            return 0.0;
        }

        return round($absentDays * $perDay, 2);
    }
}
