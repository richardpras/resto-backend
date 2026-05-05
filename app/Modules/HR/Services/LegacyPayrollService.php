<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Accounting\Domain\JournalEntry;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Payroll;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class LegacyPayrollService
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly AttendancePayrollInputService $attendancePayrollInputService,
    ) {}

    public function listByTenant(int $tenantId)
    {
        return Payroll::query()
            ->when($tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->latest('id')
            ->get();
    }

    public function create(array $payload, int $actorUserId): Payroll
    {
        $this->authorizationService->ensurePermission($actorUserId, 'payroll.create');

        return DB::transaction(function () use ($payload) {
            $employee = Employee::query()->find($payload['employeeId']);
            abort_if($employee === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Employee not found.');

            $baseAmount = (float) $employee->base_salary;
            $attendanceSummary = $this->attendancePayrollInputService->summarizeForPeriod(
                employeeId: (int) $employee->id,
                periodStart: $payload['periodStart'],
                periodEnd: $payload['periodEnd'],
            );

            $attendanceAdjustmentAmount = $this->deriveAttendanceAdjustmentAmount($attendanceSummary, $payload);
            $attendanceDeductionAmount = $this->deriveAttendanceDeductionAmount($attendanceSummary, $payload);

            $adjustmentAmount = (float) ($payload['adjustmentAmount'] ?? 0) + $attendanceAdjustmentAmount;
            $deductionAmount = (float) ($payload['deductionAmount'] ?? 0) + $attendanceDeductionAmount;
            $netAmount = $baseAmount + $adjustmentAmount - $deductionAmount;
            abort_if($netAmount < 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Net payroll amount cannot be negative.');

            $journal = $this->createPayrollJournal(
                tenantId: $payload['tenantId'] ?? null,
                employeeId: (int) $employee->id,
                amount: $netAmount,
                cashAccountCode: $payload['cashAccountCode'] ?? '1001',
                salaryExpenseAccountCode: $payload['salaryExpenseAccountCode'] ?? '5001',
            );

            return Payroll::query()->create([
                'tenant_id' => $payload['tenantId'] ?? null,
                'employee_id' => $employee->id,
                'period_start' => $payload['periodStart'],
                'period_end' => $payload['periodEnd'],
                'base_amount' => $baseAmount,
                'adjustment_amount' => $adjustmentAmount,
                'deduction_amount' => $deductionAmount,
                'net_amount' => $netAmount,
                'status' => 'posted',
                'journal_id' => $journal->id,
                'adjustments' => array_merge(
                    $payload['adjustments'] ?? [],
                    [
                        'attendanceSummary' => array_merge($attendanceSummary, [
                            'derivedAdjustmentAmount' => $attendanceAdjustmentAmount,
                            'derivedDeductionAmount' => $attendanceDeductionAmount,
                        ]),
                    ]
                ),
            ]);
        });
    }

    private function deriveAttendanceAdjustmentAmount(array $attendanceSummary, array $payload): float
    {
        $overtimeAdjustmentPerMinute = (float) ($payload['overtimeAdjustmentPerMinute'] ?? 0);

        return (float) $attendanceSummary['overtimeMinutes'] * $overtimeAdjustmentPerMinute;
    }

    private function deriveAttendanceDeductionAmount(array $attendanceSummary, array $payload): float
    {
        $lateDeductionPerCount = (float) ($payload['lateDeductionPerCount'] ?? 0);
        $absentDeductionPerCount = (float) ($payload['absentDeductionPerCount'] ?? 0);

        return ((float) $attendanceSummary['lateCount'] * $lateDeductionPerCount)
            + ((float) $attendanceSummary['absentCount'] * $absentDeductionPerCount);
    }

    private function createPayrollJournal(
        ?int $tenantId,
        int $employeeId,
        float $amount,
        string $cashAccountCode,
        string $salaryExpenseAccountCode
    ): Journal {
        $cashAccount = Account::query()->where('code', $cashAccountCode)->first();
        $salaryExpenseAccount = Account::query()->where('code', $salaryExpenseAccountCode)->first();
        abort_if(
            $cashAccount === null || $salaryExpenseAccount === null,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Required accounts for payroll posting were not found.'
        );

        $journal = Journal::query()->create([
            'tenant_id' => $tenantId,
            'journal_no' => 'JRN-PAY-'.now()->format('YmdHis').'-'.$employeeId,
            'source_type' => 'payroll',
            'source_id' => $employeeId,
            'journal_date' => now()->toDateString(),
            'status' => 'posted',
            'description' => 'Payroll posting',
        ]);

        JournalEntry::query()->create([
            'journal_id' => $journal->id,
            'account_id' => $salaryExpenseAccount->id,
            'debit' => $amount,
            'credit' => 0,
            'memo' => 'Payroll expense',
            'line_no' => 1,
        ]);
        JournalEntry::query()->create([
            'journal_id' => $journal->id,
            'account_id' => $cashAccount->id,
            'debit' => 0,
            'credit' => $amount,
            'memo' => 'Payroll cash out',
            'line_no' => 2,
        ]);

        return $journal;
    }
}
