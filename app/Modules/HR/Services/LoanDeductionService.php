<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeLoan;
use App\Models\Modules\HR\Domain\EmployeeLoanInstallment;
use Illuminate\Support\Collection;

/**
 * Read-only loan deduction preview for a payroll period (no payroll table writes).
 */
class LoanDeductionService
{
    /**
     * @return array{loanDeduction: float, remainingBalance: float, installments: Collection<int, EmployeeLoanInstallment>}
     */
    public function deductionForEmployeeInPeriod(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $installments = EmployeeLoanInstallment::query()
            ->whereHas('loan', function ($q) use ($employeeId) {
                $q->where('employee_id', $employeeId)
                    ->where('status', EmployeeLoan::STATUS_ACTIVE);
            })
            ->where('status', EmployeeLoanInstallment::STATUS_UNPAID)
            ->whereBetween('due_date', [$periodStart, $periodEnd])
            ->orderBy('due_date')
            ->get();

        $loanDeduction = round((float) $installments->sum('amount'), 2);

        $remainingBalance = $this->remainingBalanceForEmployee($employeeId);

        return [
            'loanDeduction' => $loanDeduction,
            'remainingBalance' => $remainingBalance,
            'installments' => $installments,
        ];
    }

    public function remainingBalanceForEmployee(int $employeeId): float
    {
        $loans = EmployeeLoan::query()
            ->where('employee_id', $employeeId)
            ->where('status', EmployeeLoan::STATUS_ACTIVE)
            ->get();

        if ($loans->isEmpty()) {
            return 0.0;
        }

        return round((float) $loans->sum('remaining_balance'), 2);
    }
}
