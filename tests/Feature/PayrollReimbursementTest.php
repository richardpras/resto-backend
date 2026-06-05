<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\EmployeeReimbursement;
use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\TestCase;

class PayrollReimbursementTest extends TestCase
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

    public function test_approved_claims_included_in_gross_and_marked_paid_on_finalize(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 4000000,
            'default_allowance' => 0,
            'default_deduction' => 0,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'review_required' => false,
        ]);

        $approved = EmployeeReimbursement::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'claim_no' => 'RMB-APPROVED',
            'category' => 'transport',
            'title' => 'Taxi',
            'claim_amount' => 250000,
            'expense_date' => '2026-10-15',
            'status' => EmployeeReimbursement::STATUS_APPROVED,
            'approved_at' => '2026-10-20 10:00:00',
        ]);

        EmployeeReimbursement::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'claim_no' => 'RMB-REJECTED',
            'category' => 'meal',
            'title' => 'Rejected lunch',
            'claim_amount' => 999999,
            'expense_date' => '2026-10-10',
            'status' => EmployeeReimbursement::STATUS_REJECTED,
            'rejected_at' => now(),
        ]);

        EmployeeReimbursement::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'claim_no' => 'RMB-CANCELLED',
            'category' => 'fuel',
            'title' => 'Cancelled fuel',
            'claim_amount' => 888888,
            'expense_date' => '2026-10-12',
            'status' => EmployeeReimbursement::STATUS_CANCELLED,
        ]);

        $journalBefore = DB::table('journal_entries')->count();

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(4250000.0, (float) $item->gross_salary);
        $this->assertEquals(250000.0, (float) $item->reimbursement_earning);
        $this->assertEquals(4250000.0, (float) $item->net_salary);

        $itemsRes = $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/items')->assertOk();
        $this->assertEquals(250000.0, (float) $itemsRes->json('meta.totalReimbursements'));

        $approved->refresh();
        $this->assertSame($item->id, (int) $approved->payroll_run_item_id);
        $this->assertSame(EmployeeReimbursement::STATUS_APPROVED, $approved->status);

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/approve')->assertOk();
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/finalize')->assertOk();

        $approved->refresh();
        $this->assertSame(EmployeeReimbursement::STATUS_PAID, $approved->status);
        $this->assertNotNull($approved->paid_at);

        $this->assertSame($journalBefore, DB::table('journal_entries')->count());
    }

    public function test_draft_and_submitted_claims_not_applied(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 3000000,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'review_required' => false,
        ]);

        EmployeeReimbursement::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'claim_no' => 'RMB-DRAFT',
            'category' => 'other',
            'title' => 'Draft only',
            'claim_amount' => 500000,
            'expense_date' => '2026-10-01',
            'status' => EmployeeReimbursement::STATUS_DRAFT,
        ]);

        EmployeeReimbursement::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'claim_no' => 'RMB-SUBMITTED',
            'category' => 'other',
            'title' => 'Awaiting approval',
            'claim_amount' => 600000,
            'expense_date' => '2026-10-02',
            'status' => EmployeeReimbursement::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(0.0, (float) $item->reimbursement_earning);
        $this->assertEquals(3000000.0, (float) $item->gross_salary);
    }
}
