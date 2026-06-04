<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Adjustment;
use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Loan;
use App\Models\Modules\HR\Domain\LoanPayment;
use App\Models\Modules\HR\Domain\Overtime;
use App\Models\Modules\HR\Domain\PayrollLine;
use App\Models\Modules\HR\Domain\PayrollRun;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PayrollRunService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    public function listTable(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['perPage'] ?? 10);
        if (! in_array($perPage, [10, 25, 50], true)) {
            $perPage = 10;
        }

        $query = PayrollLine::query()
            ->from('payroll_lines as pl')
            ->join('payroll_runs as pr', 'pr.id', '=', 'pl.payroll_run_id')
            ->join('employees as e', 'e.id', '=', 'pl.employee_id')
            ->select([
                'pl.id',
                'pl.payroll_run_id as payrollRunId',
                'pl.employee_id as employeeId',
                'e.full_name as employeeName',
                'e.outlet as employeeOutlet',
                'pr.period',
                'pr.outlet',
                'pr.status',
                'pl.base_salary as basicSalary',
                'pl.overtime_pay as overtimeAmount',
                DB::raw('(pl.deductions + pl.loan_deduction + pl.pph21) as deductionAmount'),
                'pl.net_salary as netSalary',
                'pl.payment_status as paymentStatus',
            ]);

        if (! empty($filters['periodFrom'])) {
            $query->where('pr.period', '>=', (string) $filters['periodFrom']);
        }
        if (! empty($filters['periodTo'])) {
            $query->where('pr.period', '<=', (string) $filters['periodTo']);
        }
        if (! empty($filters['outlet'])) {
            $query->where('pr.outlet', (string) $filters['outlet']);
        }
        if (! empty($filters['employeeId'])) {
            $query->where('pl.employee_id', (int) $filters['employeeId']);
        }
        if (! empty($filters['status'])) {
            $status = (string) $filters['status'];
            if ($status === 'paid') {
                $query->where('pr.status', 'paid');
            } elseif ($status === 'unpaid') {
                $query->where('pr.status', '!=', 'paid');
            }
        }
        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where('e.full_name', 'like', '%'.$search.'%');
        }

        return $query
            ->orderByDesc('pr.period')
            ->orderByDesc('pl.id')
            ->paginate($perPage);
    }

    public function detail(int $lineId): array
    {
        $line = PayrollLine::query()
            ->with(['employee', 'run'])
            ->find($lineId);
        abort_if($line === null, Response::HTTP_NOT_FOUND, 'Payroll detail not found.');

        $period = $line->run?->period;
        abort_if($period === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Payroll run period is missing.');

        $periodStart = Carbon::createFromFormat('Y-m', $period)->startOfMonth()->toDateString();
        $periodEnd = Carbon::createFromFormat('Y-m', $period)->endOfMonth()->toDateString();

        $lateCount = Attendance::query()
            ->where('employee_id', $line->employee_id)
            ->whereBetween('attendance_date', [$periodStart, $periodEnd])
            ->where('status', 'late')
            ->count();
        $absentCount = Attendance::query()
            ->where('employee_id', $line->employee_id)
            ->whereBetween('attendance_date', [$periodStart, $periodEnd])
            ->where('status', 'absent')
            ->count();
        $overtimeMinutes = (int) round(((float) $line->overtime_hours) * 60);

        return [
            'id' => (int) $line->id,
            'payrollRunId' => (int) $line->payroll_run_id,
            'employeeId' => (int) $line->employee_id,
            'employeeName' => $line->employee?->full_name,
            'period' => $period,
            'status' => $line->run?->status,
            'attendanceSummary' => [
                'lateCount' => $lateCount,
                'absentCount' => $absentCount,
                'overtimeMinutes' => $overtimeMinutes,
            ],
            'salaryBreakdown' => [
                'basicSalary' => (float) $line->base_salary,
                'allowance' => (float) $line->allowances,
                'deductions' => (float) $line->deductions + (float) $line->loan_deduction + (float) $line->pph21,
            ],
            'earningsBreakdown' => [
                'basicSalary' => (float) $line->base_salary,
                'attendanceAdjustment' => (float) $line->attendance_adjustment,
                'overtimePay' => (float) $line->overtime_pay,
                'allowance' => (float) $line->allowances,
                'taxableIncome' => (float) $line->taxable_income,
            ],
            'deductionBreakdown' => [
                'adjustmentDeductions' => (float) $line->deductions,
                'loanDeduction' => (float) $line->loan_deduction,
                'pph21' => (float) $line->pph21,
                'totalDeduction' => (float) $line->deductions + (float) $line->loan_deduction + (float) $line->pph21,
            ],
            'netSalary' => (float) $line->net_salary,
        ];
    }

    public function list(): Collection
    {
        return PayrollRun::query()->with('lines')->latest('id')->get();
    }

    public function run(array $payload, int $actorUserId): PayrollRun
    {
        return DB::transaction(function () use ($payload, $actorUserId) {
            $periodStart = Carbon::createFromFormat('Y-m', $payload['period'])->startOfMonth()->startOfDay();
            $periodEnd = Carbon::createFromFormat('Y-m', $payload['period'])->endOfMonth()->endOfDay();
            $workingDays = 22;
            $outlet = ($payload['outlet'] ?? null) !== '' ? ($payload['outlet'] ?? null) : null;

            $duplicateRun = PayrollRun::query()
                ->where('period', $payload['period'])
                ->when($outlet === null, fn ($query) => $query->whereNull('outlet'))
                ->when($outlet !== null, fn ($query) => $query->where('outlet', $outlet))
                ->exists();
            abort_if($duplicateRun, Response::HTTP_UNPROCESSABLE_ENTITY, 'Payroll run for this period and outlet already exists.');

            $employees = $this->employeeMaster
                ->applyPayrollOutletLabelFilter(Employee::query(), $outlet)
                ->where(function ($query) use ($periodEnd): void {
                    $query->whereNull('hire_date')
                        ->orWhereDate('hire_date', '<=', $periodEnd->toDateString());
                })
                ->where(function ($query) use ($periodStart): void {
                    $query->whereNull('termination_date')
                        ->orWhereDate('termination_date', '>=', $periodStart->toDateString());
                })
                ->get();
            $employeeIds = $employees->pluck('id');

            $attendancesByEmployee = Attendance::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('attendance_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->get()
                ->groupBy('employee_id');

            $overtimesByEmployee = Overtime::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->where('status', 'approved')
                ->get()
                ->groupBy('employee_id');

            $adjustmentsByEmployee = Adjustment::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->get()
                ->groupBy('employee_id');

            $activeLoansByEmployee = Loan::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'active')
                ->orderBy('id')
                ->get()
                ->groupBy('employee_id');

            $run = PayrollRun::query()->create([
                'period' => $payload['period'],
                'outlet' => $outlet,
                'status' => 'draft',
                'paid_at' => null,
                'created_by' => $actorUserId,
                'updated_by' => $actorUserId,
            ]);

            foreach ($employees as $employee) {
                $effectivePeriodEnd = $periodEnd->copy();
                if ($employee->termination_date !== null) {
                    $terminationDate = Carbon::parse($employee->termination_date)->endOfDay();
                    if ($terminationDate->lessThan($effectivePeriodEnd)) {
                        $effectivePeriodEnd = $terminationDate;
                    }
                }

                /** @var SupportCollection<int, Attendance> $empAttendances */
                $empAttendances = $attendancesByEmployee->get($employee->id, collect())
                    ->filter(fn (Attendance $attendance) => Carbon::parse($attendance->attendance_date)->endOfDay()->lessThanOrEqualTo($effectivePeriodEnd));
                $presentDays = $empAttendances->where('status', '!=', 'absent')->count();

                /** @var SupportCollection<int, Overtime> $empOvertimes */
                $empOvertimes = $overtimesByEmployee->get($employee->id, collect())
                    ->filter(fn (Overtime $overtime) => Carbon::parse($overtime->date)->endOfDay()->lessThanOrEqualTo($effectivePeriodEnd));
                $otHours = (float) $empOvertimes->sum('hours');

                /** @var SupportCollection<int, Adjustment> $empAdjustments */
                $empAdjustments = $adjustmentsByEmployee->get($employee->id, collect())
                    ->filter(fn (Adjustment $adjustment) => Carbon::parse($adjustment->date)->endOfDay()->lessThanOrEqualTo($effectivePeriodEnd));
                $allowances = (float) $empAdjustments->where('type', 'allowance')->sum('amount');
                $deductions = (float) $empAdjustments->where('type', 'deduction')->sum('amount');

                $baseSalary = 0.0;
                $attendanceAdjustment = 0.0;
                $employeeWorkingDays = $workingDays;
                $terminationDate = $employee->termination_date !== null ? Carbon::parse($employee->termination_date) : null;
                $isTerminatedInPeriod = $terminationDate !== null
                    && $terminationDate->greaterThanOrEqualTo($periodStart)
                    && $terminationDate->lessThanOrEqualTo($periodEnd);
                if ($isTerminatedInPeriod) {
                    $employeeWorkingDays = $this->countWeekdays($periodStart, $effectivePeriodEnd);
                    if ($employeeWorkingDays <= 0) {
                        $employeeWorkingDays = 1;
                    }
                }

                if ($employee->salary_type === 'monthly') {
                    $baseSalary = $isTerminatedInPeriod
                        ? round(((float) $employee->base_salary / $workingDays) * $employeeWorkingDays)
                        : (float) $employee->base_salary;
                    $absentDays = max(0, $employeeWorkingDays - $presentDays);
                    $attendanceAdjustment = -1 * round(((float) $baseSalary / $employeeWorkingDays) * $absentDays);
                } elseif ($employee->salary_type === 'daily') {
                    $baseSalary = (float) $employee->base_salary * $presentDays;
                } else {
                    $baseSalary = (float) $employee->base_salary * $presentDays * 8;
                }

                $overtimePay = $otHours * (float) $employee->overtime_rate;

                /** @var Loan|null $activeLoan */
                $activeLoan = $activeLoansByEmployee->get($employee->id, collect())->first();
                $loanDeduction = ($activeLoan !== null && (int) $activeLoan->installments > 0)
                    ? round((float) $activeLoan->amount / (int) $activeLoan->installments)
                    : 0.0;

                $taxableIncome = $baseSalary + $attendanceAdjustment + $overtimePay + $allowances;
                $pph21 = $this->calcPPH21($taxableIncome);
                $netSalary = $taxableIncome - $deductions - $loanDeduction - $pph21;

                PayrollLine::query()->create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'base_salary' => $baseSalary,
                    'attendance_adjustment' => $attendanceAdjustment,
                    'overtime_pay' => $overtimePay,
                    'allowances' => $allowances,
                    'deductions' => $deductions,
                    'loan_deduction' => $loanDeduction,
                    'taxable_income' => $taxableIncome,
                    'pph21' => $pph21,
                    'net_salary' => $netSalary,
                    'working_days' => $employeeWorkingDays,
                    'present_days' => $presentDays,
                    'overtime_hours' => $otHours,
                    'payment_status' => 'unlocked',
                    'created_by' => $actorUserId,
                    'updated_by' => $actorUserId,
                ]);
            }

            return $run->load('lines');
        });
    }

    public function finalize(int $runId, int $actorUserId): PayrollRun
    {
        $run = PayrollRun::query()->find($runId);
        abort_if($run === null, Response::HTTP_NOT_FOUND, 'Payroll run not found.');

        $run->status = 'processed';
        $run->updated_by = $actorUserId;
        $run->save();

        return $run->load('lines');
    }

    public function pay(int $runId, int $actorUserId): PayrollRun
    {
        return DB::transaction(function () use ($runId, $actorUserId) {
            $run = PayrollRun::query()->with('lines')->find($runId);
            abort_if($run === null, Response::HTTP_NOT_FOUND, 'Payroll run not found.');

            $lockedLines = $run->lines->where('payment_status', 'locked')->values();
            $allLinesLocked = $run->lines->isNotEmpty() && $run->lines->every(fn ($line) => $line->payment_status === 'locked');

            $employeeIds = $lockedLines->pluck('employee_id')->filter()->values();
            $activeLoansByEmployee = Loan::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'active')
                ->orderBy('id')
                ->get()
                ->groupBy('employee_id');

            foreach ($lockedLines as $line) {
                if ((float) $line->loan_deduction > 0) {
                    /** @var Loan|null $loan */
                    $loan = $activeLoansByEmployee->get($line->employee_id, collect())->first();

                    if ($loan !== null) {
                        $paidInstallments = (int) $loan->paid_installments + 1;
                        LoanPayment::query()->create([
                            'loan_id' => $loan->id,
                            'payroll_run_id' => $run->id,
                            'amount' => (float) $line->loan_deduction,
                            'installment_no' => $paidInstallments,
                            'paid_at' => now(),
                            'created_by' => $actorUserId,
                        ]);

                        $loan->paid_installments = $paidInstallments;
                        $loan->status = $paidInstallments >= (int) $loan->installments ? 'completed' : 'active';
                        $loan->updated_by = $actorUserId;
                        $loan->save();
                    }
                }

                $line->payment_status = 'unlocked';
                $line->updated_by = $actorUserId;
                $line->save();
            }

            if ($allLinesLocked) {
                $run->status = 'paid';
                $run->paid_at = now();
            } else {
                $run->status = 'processed';
            }
            $run->updated_by = $actorUserId;
            $run->save();

            return $run->load('lines');
        });
    }

    public function lockLine(int $lineId, int $actorUserId): PayrollLine
    {
        $line = PayrollLine::query()->find($lineId);
        abort_if($line === null, Response::HTTP_NOT_FOUND, 'Payroll line not found.');
        $line->payment_status = 'locked';
        $line->updated_by = $actorUserId;
        $line->save();

        return $line->refresh();
    }

    public function unlockLine(int $lineId, int $actorUserId): PayrollLine
    {
        $line = PayrollLine::query()->find($lineId);
        abort_if($line === null, Response::HTTP_NOT_FOUND, 'Payroll line not found.');
        $line->payment_status = 'unlocked';
        $line->updated_by = $actorUserId;
        $line->save();

        return $line->refresh();
    }

    private function calcPPH21(float $monthlyTaxable): float
    {
        $annual = $monthlyTaxable * 12;
        $ptkp = 54000000;
        $pkp = max(0, $annual - $ptkp);
        $tax = 0.0;
        $remaining = $pkp;
        $brackets = [
            [60000000, 0.05],
            [190000000, 0.15],
            [250000000, 0.25],
            [4500000000, 0.3],
            [INF, 0.35],
        ];

        foreach ($brackets as [$limit, $rate]) {
            $slice = min($remaining, $limit);
            $tax += $slice * $rate;
            $remaining -= $slice;
            if ($remaining <= 0) {
                break;
            }
        }

        return (float) round($tax / 12);
    }

    private function countWeekdays(Carbon $startDate, Carbon $endDate): int
    {
        $start = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();
        $days = 0;

        while ($start->lessThanOrEqualTo($end)) {
            if (! $start->isWeekend()) {
                $days++;
            }
            $start->addDay();
        }

        return $days;
    }
}
