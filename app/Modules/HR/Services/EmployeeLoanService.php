<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeLoan;
use App\Models\Modules\HR\Domain\EmployeeLoanInstallment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EmployeeLoanService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, EmployeeLoan>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = EmployeeLoan::query()
            ->with(['employee', 'installments'])
            ->orderByDesc('id');

        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');

        if (! empty($filters['outletId'])) {
            $query->where('outlet_id', (int) $filters['outletId']);
        }

        if (! empty($filters['employeeId'])) {
            $query->where('employee_id', (int) $filters['employeeId']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $loanId): EmployeeLoan
    {
        $loan = EmployeeLoan::query()
            ->with(['employee', 'installments'])
            ->find($loanId);

        abort_if($loan === null, Response::HTTP_NOT_FOUND, 'Employee loan not found.');

        $loan->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $loan->employee);

        return $loan;
    }

    public function create(?User $user, array $payload): EmployeeLoan
    {
        $employee = $this->employeeMaster->findAccessible($user, (int) ($payload['employeeId'] ?? 0));

        $principal = round((float) ($payload['principalAmount'] ?? 0), 2);
        $installmentAmount = round((float) ($payload['installmentAmount'] ?? 0), 2);
        $totalInstallments = (int) ($payload['totalInstallments'] ?? 0);

        if ($principal <= 0 || $installmentAmount <= 0 || $totalInstallments < 1) {
            throw ValidationException::withMessages([
                'principalAmount' => ['Principal, installment amount, and total installments are required.'],
            ]);
        }

        $loan = EmployeeLoan::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'loan_no' => 'TEMP',
            'principal_amount' => $principal,
            'installment_amount' => $installmentAmount,
            'total_installments' => $totalInstallments,
            'paid_installments' => 0,
            'remaining_balance' => $principal,
            'status' => EmployeeLoan::STATUS_PENDING,
        ]);

        $loan->update([
            'loan_no' => sprintf('LOAN-%d-%04d', (int) $employee->outlet_id, (int) $loan->id),
        ]);

        return $loan->refresh()->load('employee');
    }

    public function update(?User $user, int $loanId, array $payload): EmployeeLoan
    {
        $loan = $this->findAccessible($user, $loanId);

        if ($loan->status !== EmployeeLoan::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending loans can be updated.'],
            ]);
        }

        $data = [];
        if (array_key_exists('principalAmount', $payload)) {
            $data['principal_amount'] = round((float) $payload['principalAmount'], 2);
        }
        if (array_key_exists('installmentAmount', $payload)) {
            $data['installment_amount'] = round((float) $payload['installmentAmount'], 2);
        }
        if (array_key_exists('totalInstallments', $payload)) {
            $data['total_installments'] = (int) $payload['totalInstallments'];
        }

        if ($data !== []) {
            $loan->update($data);
            $loan->update(['remaining_balance' => (float) $loan->principal_amount]);
        }

        return $loan->refresh()->load(['employee', 'installments']);
    }

    public function approve(?User $user, int $loanId): EmployeeLoan
    {
        $loan = $this->findAccessible($user, $loanId);

        if ($loan->status !== EmployeeLoan::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending loans can be approved.'],
            ]);
        }

        $loan->update([
            'status' => EmployeeLoan::STATUS_APPROVED,
            'approved_by' => $user?->id,
            'approved_at' => now(),
        ]);

        return $loan->refresh()->load(['employee', 'installments']);
    }

    public function activate(?User $user, int $loanId): EmployeeLoan
    {
        $loan = $this->findAccessible($user, $loanId);

        if ($loan->status !== EmployeeLoan::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => ['Only approved loans can be activated.'],
            ]);
        }

        $loan->update(['status' => EmployeeLoan::STATUS_ACTIVE]);
        $this->generateInstallmentSchedule($loan);

        return $loan->refresh()->load(['employee', 'installments']);
    }

    public function cancel(?User $user, int $loanId): EmployeeLoan
    {
        $loan = $this->findAccessible($user, $loanId);

        if (! in_array($loan->status, [EmployeeLoan::STATUS_PENDING, EmployeeLoan::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or approved loans can be cancelled.'],
            ]);
        }

        $loan->update(['status' => EmployeeLoan::STATUS_CANCELLED]);

        return $loan->refresh()->load(['employee', 'installments']);
    }

    public function completeIfFullyPaid(EmployeeLoan $loan): void
    {
        $loan->refresh();
        $unpaidCount = $loan->installments()->where('status', EmployeeLoanInstallment::STATUS_UNPAID)->count();

        if ($unpaidCount === 0 && $loan->status === EmployeeLoan::STATUS_ACTIVE) {
            $loan->update([
                'status' => EmployeeLoan::STATUS_COMPLETED,
                'remaining_balance' => 0,
            ]);
        }
    }

    public function syncLoanBalances(EmployeeLoan $loan): void
    {
        $loan->refresh();
        $deducted = $loan->installments()->where('status', EmployeeLoanInstallment::STATUS_DEDUCTED)->count();
        $unpaidSum = (float) $loan->installments()
            ->where('status', EmployeeLoanInstallment::STATUS_UNPAID)
            ->sum('amount');

        $loan->update([
            'paid_installments' => $deducted,
            'remaining_balance' => round($unpaidSum, 2),
        ]);

        $this->completeIfFullyPaid($loan);
    }

    /**
     * @return Collection<int, EmployeeLoanInstallment>
     */
    public function installments(?User $user, int $loanId): Collection
    {
        $loan = $this->findAccessible($user, $loanId);

        return $loan->installments()->orderBy('installment_no')->get();
    }

    public function generateInstallmentSchedule(EmployeeLoan $loan): void
    {
        EmployeeLoanInstallment::query()->where('loan_id', $loan->id)->delete();

        $start = Carbon::parse($loan->approved_at ?? now())->startOfMonth();

        for ($i = 1; $i <= (int) $loan->total_installments; $i++) {
            EmployeeLoanInstallment::query()->create([
                'loan_id' => $loan->id,
                'installment_no' => $i,
                'due_date' => $start->copy()->addMonths($i - 1)->toDateString(),
                'amount' => (float) $loan->installment_amount,
                'status' => EmployeeLoanInstallment::STATUS_UNPAID,
            ]);
        }

        $loan->update(['remaining_balance' => (float) $loan->principal_amount]);
    }

    /**
     * Apply payroll deductions for a calculated run item.
     */
    public function applyPayrollDeductions(
        int $payrollRunId,
        int $payrollRunItemId,
        int $employeeId,
        string $periodStart,
        string $periodEnd,
        LoanDeductionService $deductionService,
    ): array {
        EmployeeLoanInstallment::query()
            ->where('payroll_run_item_id', $payrollRunItemId)
            ->update([
                'status' => EmployeeLoanInstallment::STATUS_UNPAID,
                'payroll_run_item_id' => null,
            ]);

        $preview = $deductionService->deductionForEmployeeInPeriod($employeeId, $periodStart, $periodEnd);

        foreach ($preview['installments'] as $installment) {
            $installment->update([
                'status' => EmployeeLoanInstallment::STATUS_DEDUCTED,
                'payroll_run_item_id' => $payrollRunItemId,
            ]);

            $this->syncLoanBalances($installment->loan);
        }

        return [
            'loanDeduction' => $preview['loanDeduction'],
            'remainingBalance' => $deductionService->remainingBalanceForEmployee($employeeId),
        ];
    }

    /**
     * Reset installments linked to a payroll run before recalculation.
     */
    public function resetDeductionsForPayrollRun(int $payrollRunId): void
    {
        $itemIds = \App\Models\Modules\HR\Domain\PayrollRunItemV2::query()
            ->where('payroll_run_id', $payrollRunId)
            ->pluck('id');

        $installments = EmployeeLoanInstallment::query()
            ->whereIn('payroll_run_item_id', $itemIds)
            ->get();

        foreach ($installments as $installment) {
            $installment->update([
                'status' => EmployeeLoanInstallment::STATUS_UNPAID,
                'payroll_run_item_id' => null,
            ]);
            $this->syncLoanBalances($installment->loan);
        }
    }
}
