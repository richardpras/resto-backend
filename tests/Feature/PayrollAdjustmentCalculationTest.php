<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollAdjustment;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\TestCase;

class PayrollAdjustmentCalculationTest extends TestCase
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

    public function test_earning_and_deduction_adjustments_in_payroll(): void
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

        PayrollAdjustment::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'adjustment_no' => 'ADJ-EARN',
            'type' => PayrollAdjustment::TYPE_EARNING,
            'category' => PayrollAdjustment::CATEGORY_BONUS,
            'amount' => 500000,
            'effective_from' => '2026-10-01',
            'effective_to' => '2026-10-31',
            'status' => PayrollAdjustment::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        PayrollAdjustment::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'adjustment_no' => 'ADJ-DED',
            'type' => PayrollAdjustment::TYPE_DEDUCTION,
            'category' => PayrollAdjustment::CATEGORY_PENALTY,
            'amount' => 150000,
            'effective_from' => '2026-10-01',
            'effective_to' => '2026-10-31',
            'status' => PayrollAdjustment::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $journalBefore = DB::table('journal_entries')->count();

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(5500000.0, (float) $item->gross_salary);
        $this->assertEquals(500000.0, (float) $item->adjustment_earning);
        $this->assertEquals(150000.0, (float) $item->adjustment_deduction);
        $this->assertEquals(150000.0, (float) $item->total_deductions);
        $this->assertEquals(5350000.0, (float) $item->net_salary);

        $calc = $item->calculation_json;
        $this->assertEquals(500000.0, (float) ($calc['adjustmentEarning'] ?? 0));
        $this->assertEquals(150000.0, (float) ($calc['adjustmentDeduction'] ?? 0));

        $itemsRes = $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/items')->assertOk();
        $this->assertEquals(500000.0, (float) $itemsRes->json('meta.totalBonus'));
        $this->assertEquals(150000.0, (float) $itemsRes->json('meta.totalAdjustmentDeduction'));

        $this->assertSame($journalBefore, DB::table('journal_entries')->count());
    }

    public function test_recalculate_picks_up_new_approved_adjustment(): void
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

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(3000000.0, (float) $item->net_salary);

        PayrollAdjustment::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'adjustment_no' => 'ADJ-RECALC',
            'type' => PayrollAdjustment::TYPE_EARNING,
            'category' => PayrollAdjustment::CATEGORY_INCENTIVE,
            'amount' => 300000,
            'effective_from' => '2026-10-01',
            'effective_to' => '2026-10-31',
            'status' => PayrollAdjustment::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item->refresh();
        $this->assertEquals(3300000.0, (float) $item->gross_salary);
        $this->assertEquals(3300000.0, (float) $item->net_salary);
        $this->assertEquals(300000.0, (float) $item->adjustment_earning);
    }

    public function test_draft_adjustment_not_applied(): void
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

        PayrollAdjustment::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'adjustment_no' => 'ADJ-DRAFT',
            'type' => PayrollAdjustment::TYPE_EARNING,
            'category' => PayrollAdjustment::CATEGORY_BONUS,
            'amount' => 999999,
            'effective_from' => '2026-10-01',
            'effective_to' => '2026-10-31',
            'status' => PayrollAdjustment::STATUS_DRAFT,
        ]);

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(0.0, (float) $item->adjustment_earning);
        $this->assertEquals(2000000.0, (float) $item->net_salary);
    }
}
