<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\TestCase;

class PayrollLeaveDeductionTest extends TestCase
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

    public function test_unpaid_leave_deduction_applied(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 3000000,
            'unpaid_leave_deduction_enabled' => true,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'attended_days' => 18,
            'unpaid_leave_days' => 2,
            'leave_days' => 2,
            'review_required' => false,
        ]);

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(2.0, (float) $item->unpaid_leave_days);
        $this->assertEquals(200000.0, (float) $item->unpaid_leave_deduction);
        $this->assertEquals(2800000.0, (float) $item->net_salary);
    }

    public function test_unpaid_leave_deduction_disabled(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 3000000,
            'unpaid_leave_deduction_enabled' => false,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'unpaid_leave_days' => 2,
            'review_required' => false,
        ]);

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(0.0, (float) $item->unpaid_leave_deduction);
    }
}
