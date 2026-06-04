<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\EmployeeLoan;
use App\Models\Modules\HR\Domain\EmployeeLoanInstallment;
use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\TestCase;

class PayrollLoanDeductionTest extends TestCase
{
    use HrmApiFixture;
    use LockedPayrollPreparationFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_payroll_calculate_includes_loan_deduction(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 5000000,
            'default_allowance' => 0,
            'default_deduction' => 0,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'review_required' => false,
        ]);

        $loan = EmployeeLoan::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'loan_no' => 'LOAN-PAY-01',
            'principal_amount' => 3000000,
            'installment_amount' => 750000,
            'total_installments' => 4,
            'paid_installments' => 0,
            'remaining_balance' => 3000000,
            'status' => EmployeeLoan::STATUS_ACTIVE,
            'approved_at' => '2026-10-01',
        ]);

        $dueDates = ['2026-10-15', '2026-11-15', '2026-12-15', '2027-01-15'];
        foreach ($dueDates as $i => $dueDate) {
            EmployeeLoanInstallment::query()->create([
                'loan_id' => $loan->id,
                'installment_no' => $i + 1,
                'due_date' => $dueDate,
                'amount' => 750000,
                'status' => EmployeeLoanInstallment::STATUS_UNPAID,
            ]);
        }

        $journalBefore = DB::table('journal_entries')->count();

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertNotNull($item);
        $this->assertEquals(750000.0, (float) $item->loan_deduction);
        $this->assertEquals(4250000.0, (float) $item->net_salary);
        $this->assertEquals(2250000.0, (float) $item->remaining_loan_balance);

        $calc = $item->calculation_json;
        $this->assertEquals(750000.0, (float) ($calc['loanDeduction'] ?? 0));

        $deductedCount = EmployeeLoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->where('status', EmployeeLoanInstallment::STATUS_DEDUCTED)
            ->count();
        $this->assertSame(1, $deductedCount);
        $deducted = EmployeeLoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->where('status', EmployeeLoanInstallment::STATUS_DEDUCTED)
            ->first();
        $this->assertNotNull($deducted);
        $this->assertSame((int) $item->id, (int) $deducted->payroll_run_item_id);

        $this->assertSame($journalBefore, DB::table('journal_entries')->count());
    }

    public function test_recalculate_resets_and_reapplies_loan_deduction(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 2000000,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'review_required' => false,
        ]);

        $loan = EmployeeLoan::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'loan_no' => 'LOAN-REC-01',
            'principal_amount' => 1000000,
            'installment_amount' => 250000,
            'total_installments' => 4,
            'paid_installments' => 0,
            'remaining_balance' => 1000000,
            'status' => EmployeeLoan::STATUS_ACTIVE,
            'approved_at' => '2026-10-01',
        ]);

        $dueDates = ['2026-10-10', '2026-11-10', '2026-12-10', '2027-01-10'];
        foreach ($dueDates as $i => $dueDate) {
            EmployeeLoanInstallment::query()->create([
                'loan_id' => $loan->id,
                'installment_no' => $i + 1,
                'due_date' => $dueDate,
                'amount' => 250000,
                'status' => EmployeeLoanInstallment::STATUS_UNPAID,
            ]);
        }

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(250000.0, (float) $item->loan_deduction);
        $this->assertEquals(1750000.0, (float) $item->net_salary);
    }
}
