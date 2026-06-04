<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeCashAdvance;
use App\Models\Modules\HR\Domain\EmployeeCashAdvanceInstallment;
use Illuminate\Support\Collection;

/**
 * Read-only cash advance deduction preview for a payroll period.
 */
class CashAdvanceDeductionService
{
    /**
     * @return array{cashAdvanceDeduction: float, remainingBalance: float, installments: Collection<int, EmployeeCashAdvanceInstallment>}
     */
    public function deductionForEmployeeInPeriod(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $installments = EmployeeCashAdvanceInstallment::query()
            ->whereHas('cashAdvance', function ($q) use ($employeeId) {
                $q->where('employee_id', $employeeId)
                    ->where('status', EmployeeCashAdvance::STATUS_ACTIVE);
            })
            ->where('status', EmployeeCashAdvanceInstallment::STATUS_UNPAID)
            ->whereBetween('due_date', [$periodStart, $periodEnd])
            ->orderBy('due_date')
            ->get();

        $cashAdvanceDeduction = round((float) $installments->sum('amount'), 2);

        return [
            'cashAdvanceDeduction' => $cashAdvanceDeduction,
            'remainingBalance' => $this->remainingBalanceForEmployee($employeeId),
            'installments' => $installments,
        ];
    }

    public function remainingBalanceForEmployee(int $employeeId): float
    {
        $advances = EmployeeCashAdvance::query()
            ->where('employee_id', $employeeId)
            ->where('status', EmployeeCashAdvance::STATUS_ACTIVE)
            ->get();

        if ($advances->isEmpty()) {
            return 0.0;
        }

        return round((float) $advances->sum('remaining_amount'), 2);
    }
}
