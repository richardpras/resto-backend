<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeCashAdvance;
use App\Models\Modules\HR\Domain\EmployeeCashAdvanceInstallment;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CashAdvanceService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, EmployeeCashAdvance>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = EmployeeCashAdvance::query()
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

    public function findAccessible(?User $user, int $advanceId): EmployeeCashAdvance
    {
        $advance = EmployeeCashAdvance::query()
            ->with(['employee', 'installments'])
            ->find($advanceId);

        abort_if($advance === null, Response::HTTP_NOT_FOUND, 'Cash advance not found.');

        $advance->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $advance->employee);

        return $advance;
    }

    public function create(?User $user, array $payload): EmployeeCashAdvance
    {
        $employee = $this->employeeMaster->findAccessible($user, (int) ($payload['employeeId'] ?? 0));

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $repaymentType = (string) ($payload['repaymentType'] ?? '');

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Advance amount is required.'],
            ]);
        }

        if (! in_array($repaymentType, [EmployeeCashAdvance::REPAYMENT_NEXT_PAYROLL, EmployeeCashAdvance::REPAYMENT_INSTALLMENT], true)) {
            throw ValidationException::withMessages([
                'repaymentType' => ['Repayment type must be next_payroll or installment.'],
            ]);
        }

        $installmentCount = null;
        $installmentAmount = null;

        if ($repaymentType === EmployeeCashAdvance::REPAYMENT_INSTALLMENT) {
            $installmentCount = (int) ($payload['installmentCount'] ?? 0);
            $installmentAmount = round((float) ($payload['installmentAmount'] ?? 0), 2);

            if ($installmentCount < 1 || $installmentAmount <= 0) {
                throw ValidationException::withMessages([
                    'installmentCount' => ['Installment count and amount are required for installment repayment.'],
                ]);
            }
        }

        $advance = EmployeeCashAdvance::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'advance_no' => 'TEMP',
            'amount' => $amount,
            'repayment_type' => $repaymentType,
            'installment_count' => $installmentCount,
            'installment_amount' => $installmentAmount,
            'deducted_amount' => 0,
            'remaining_amount' => $amount,
            'status' => EmployeeCashAdvance::STATUS_PENDING,
        ]);

        $advance->update([
            'advance_no' => sprintf('CADV-%d-%04d', (int) $employee->outlet_id, (int) $advance->id),
        ]);

        return $advance->refresh()->load('employee');
    }

    public function update(?User $user, int $advanceId, array $payload): EmployeeCashAdvance
    {
        $advance = $this->findAccessible($user, $advanceId);

        if ($advance->status !== EmployeeCashAdvance::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending cash advances can be updated.'],
            ]);
        }

        $data = [];
        if (array_key_exists('amount', $payload)) {
            $data['amount'] = round((float) $payload['amount'], 2);
        }
        if (array_key_exists('repaymentType', $payload)) {
            $type = (string) $payload['repaymentType'];
            if (! in_array($type, [EmployeeCashAdvance::REPAYMENT_NEXT_PAYROLL, EmployeeCashAdvance::REPAYMENT_INSTALLMENT], true)) {
                throw ValidationException::withMessages([
                    'repaymentType' => ['Invalid repayment type.'],
                ]);
            }
            $data['repayment_type'] = $type;
        }
        if (array_key_exists('installmentCount', $payload)) {
            $data['installment_count'] = (int) $payload['installmentCount'];
        }
        if (array_key_exists('installmentAmount', $payload)) {
            $data['installment_amount'] = round((float) $payload['installmentAmount'], 2);
        }

        if ($data !== []) {
            $advance->update($data);
            $advance->refresh();
            $this->validateRepaymentFields($advance);
            $advance->update(['remaining_amount' => (float) $advance->amount]);
        }

        return $advance->refresh()->load(['employee', 'installments']);
    }

    public function approve(?User $user, int $advanceId): EmployeeCashAdvance
    {
        $advance = $this->findAccessible($user, $advanceId);

        if ($advance->status !== EmployeeCashAdvance::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending cash advances can be approved.'],
            ]);
        }

        $this->validateRepaymentFields($advance);

        $advance->update([
            'status' => EmployeeCashAdvance::STATUS_APPROVED,
            'approved_by' => $user?->id,
            'approved_at' => now(),
        ]);

        return $advance->refresh()->load(['employee', 'installments']);
    }

    public function activate(?User $user, int $advanceId): EmployeeCashAdvance
    {
        $advance = $this->findAccessible($user, $advanceId);

        if ($advance->status !== EmployeeCashAdvance::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => ['Only approved cash advances can be activated.'],
            ]);
        }

        $advance->update(['status' => EmployeeCashAdvance::STATUS_ACTIVE]);
        $this->generateInstallmentSchedule($advance);

        return $advance->refresh()->load(['employee', 'installments']);
    }

    public function cancel(?User $user, int $advanceId): EmployeeCashAdvance
    {
        $advance = $this->findAccessible($user, $advanceId);

        if (! in_array($advance->status, [EmployeeCashAdvance::STATUS_PENDING, EmployeeCashAdvance::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or approved cash advances can be cancelled.'],
            ]);
        }

        $advance->update(['status' => EmployeeCashAdvance::STATUS_CANCELLED]);

        return $advance->refresh()->load(['employee', 'installments']);
    }

    public function completeIfFullyPaid(EmployeeCashAdvance $advance): void
    {
        $advance->refresh();
        $unpaidCount = $advance->installments()->where('status', EmployeeCashAdvanceInstallment::STATUS_UNPAID)->count();

        if ($unpaidCount === 0 && $advance->status === EmployeeCashAdvance::STATUS_ACTIVE) {
            $advance->update([
                'status' => EmployeeCashAdvance::STATUS_COMPLETED,
                'remaining_amount' => 0,
            ]);
        }
    }

    public function syncAdvanceBalances(EmployeeCashAdvance $advance): void
    {
        $advance->refresh();
        $deductedSum = (float) $advance->installments()
            ->where('status', EmployeeCashAdvanceInstallment::STATUS_DEDUCTED)
            ->sum('amount');
        $unpaidSum = (float) $advance->installments()
            ->where('status', EmployeeCashAdvanceInstallment::STATUS_UNPAID)
            ->sum('amount');

        $advance->update([
            'deducted_amount' => round($deductedSum, 2),
            'remaining_amount' => round($unpaidSum, 2),
        ]);

        $this->completeIfFullyPaid($advance);
    }

    /**
     * @return Collection<int, EmployeeCashAdvanceInstallment>
     */
    public function installments(?User $user, int $advanceId): Collection
    {
        $advance = $this->findAccessible($user, $advanceId);

        return $advance->installments()->orderBy('installment_no')->get();
    }

    public function generateInstallmentSchedule(EmployeeCashAdvance $advance): void
    {
        EmployeeCashAdvanceInstallment::query()->where('cash_advance_id', $advance->id)->delete();

        $approvedAt = Carbon::parse($advance->approved_at ?? now());

        if ($advance->repayment_type === EmployeeCashAdvance::REPAYMENT_NEXT_PAYROLL) {
            $dueDate = $approvedAt->copy()->addMonth()->endOfMonth()->toDateString();

            EmployeeCashAdvanceInstallment::query()->create([
                'cash_advance_id' => $advance->id,
                'installment_no' => 1,
                'due_date' => $dueDate,
                'amount' => (float) $advance->amount,
                'status' => EmployeeCashAdvanceInstallment::STATUS_UNPAID,
            ]);
        } else {
            $start = $approvedAt->copy()->startOfMonth();
            $count = (int) $advance->installment_count;
            $amount = (float) $advance->installment_amount;

            for ($i = 1; $i <= $count; $i++) {
                EmployeeCashAdvanceInstallment::query()->create([
                    'cash_advance_id' => $advance->id,
                    'installment_no' => $i,
                    'due_date' => $start->copy()->addMonths($i - 1)->toDateString(),
                    'amount' => $amount,
                    'status' => EmployeeCashAdvanceInstallment::STATUS_UNPAID,
                ]);
            }
        }

        $advance->update(['remaining_amount' => (float) $advance->amount]);
    }

    public function applyPayrollDeductions(
        int $payrollRunItemId,
        int $employeeId,
        string $periodStart,
        string $periodEnd,
        CashAdvanceDeductionService $deductionService,
    ): array {
        EmployeeCashAdvanceInstallment::query()
            ->where('payroll_run_item_id', $payrollRunItemId)
            ->update([
                'status' => EmployeeCashAdvanceInstallment::STATUS_UNPAID,
                'payroll_run_item_id' => null,
            ]);

        $preview = $deductionService->deductionForEmployeeInPeriod($employeeId, $periodStart, $periodEnd);

        foreach ($preview['installments'] as $installment) {
            $installment->update([
                'status' => EmployeeCashAdvanceInstallment::STATUS_DEDUCTED,
                'payroll_run_item_id' => $payrollRunItemId,
            ]);

            $this->syncAdvanceBalances($installment->cashAdvance);
        }

        return [
            'cashAdvanceDeduction' => $preview['cashAdvanceDeduction'],
            'remainingBalance' => $deductionService->remainingBalanceForEmployee($employeeId),
        ];
    }

    public function resetDeductionsForPayrollRun(int $payrollRunId): void
    {
        $itemIds = PayrollRunItemV2::query()
            ->where('payroll_run_id', $payrollRunId)
            ->pluck('id');

        $installments = EmployeeCashAdvanceInstallment::query()
            ->whereIn('payroll_run_item_id', $itemIds)
            ->get();

        foreach ($installments as $installment) {
            $installment->update([
                'status' => EmployeeCashAdvanceInstallment::STATUS_UNPAID,
                'payroll_run_item_id' => null,
            ]);
            $this->syncAdvanceBalances($installment->cashAdvance);
        }
    }

    private function validateRepaymentFields(EmployeeCashAdvance $advance): void
    {
        if ($advance->repayment_type === EmployeeCashAdvance::REPAYMENT_INSTALLMENT) {
            if ((int) $advance->installment_count < 1 || (float) $advance->installment_amount <= 0) {
                throw ValidationException::withMessages([
                    'installmentCount' => ['Installment count and amount are required for installment repayment.'],
                ]);
            }
        }
    }
}
