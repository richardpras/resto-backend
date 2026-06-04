<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\EmployeeCashAdvance;
use App\Models\Modules\HR\Domain\EmployeeCashAdvanceInstallment;
use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\TestCase;

class PayrollCashAdvanceDeductionTest extends TestCase
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

    public function test_next_payroll_deduction_on_calculate(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 4000000,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'review_required' => false,
        ]);

        $advance = EmployeeCashAdvance::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'advance_no' => 'CADV-PAY-NP',
            'amount' => 1000000,
            'repayment_type' => EmployeeCashAdvance::REPAYMENT_NEXT_PAYROLL,
            'deducted_amount' => 0,
            'remaining_amount' => 1000000,
            'status' => EmployeeCashAdvance::STATUS_ACTIVE,
            'approved_at' => '2026-09-01',
        ]);

        EmployeeCashAdvanceInstallment::query()->create([
            'cash_advance_id' => $advance->id,
            'installment_no' => 1,
            'due_date' => '2026-10-31',
            'amount' => 1000000,
            'status' => EmployeeCashAdvanceInstallment::STATUS_UNPAID,
        ]);

        $journalBefore = DB::table('journal_entries')->count();

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(1000000.0, (float) $item->cash_advance_deduction);
        $this->assertEquals(3000000.0, (float) $item->net_salary);
        $this->assertEquals(0.0, (float) $item->remaining_cash_advance_balance);

        $advance->refresh();
        $this->assertSame(EmployeeCashAdvance::STATUS_COMPLETED, $advance->status);

        $this->assertSame($journalBefore, DB::table('journal_entries')->count());
    }

    public function test_installment_deduction_and_remaining_balance(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 5000000,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'review_required' => false,
        ]);

        $advance = EmployeeCashAdvance::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'advance_no' => 'CADV-PAY-INST',
            'amount' => 900000,
            'repayment_type' => EmployeeCashAdvance::REPAYMENT_INSTALLMENT,
            'installment_count' => 3,
            'installment_amount' => 300000,
            'deducted_amount' => 0,
            'remaining_amount' => 900000,
            'status' => EmployeeCashAdvance::STATUS_ACTIVE,
            'approved_at' => '2026-09-01',
        ]);

        $dueDates = ['2026-10-10', '2026-11-10', '2026-12-10'];
        foreach ($dueDates as $i => $dueDate) {
            EmployeeCashAdvanceInstallment::query()->create([
                'cash_advance_id' => $advance->id,
                'installment_no' => $i + 1,
                'due_date' => $dueDate,
                'amount' => 300000,
                'status' => EmployeeCashAdvanceInstallment::STATUS_UNPAID,
            ]);
        }

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(300000.0, (float) $item->cash_advance_deduction);
        $this->assertEquals(4700000.0, (float) $item->net_salary);
        $this->assertEquals(600000.0, (float) $item->remaining_cash_advance_balance);

        $advance->refresh();
        $this->assertSame(EmployeeCashAdvance::STATUS_ACTIVE, $advance->status);
        $this->assertEquals(600000.0, (float) $advance->remaining_amount);
    }
}
