<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\TestCase;

class PayrollEngineRecalculationTest extends TestCase
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

    public function test_approved_and_finalized_cannot_recalculate(): void
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

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/approve')->assertOk();
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertStatus(422);

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/finalize')->assertOk();
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertStatus(422);

        $run = PayrollRunV2::query()->find($runId);
        $this->assertSame(PayrollRunV2::STATUS_FINALIZED, $run->status);
    }

    public function test_calculated_run_can_recalculate(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 1000000,
            'overtime_rate_type' => 'fixed_hourly',
            'overtime_rate_value' => 10000,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'overtime_hours' => 5,
            'review_required' => false,
        ]);

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        PayrollPreparationSnapshot::query()
            ->where('preparation_period_id', $period->id)
            ->update(['overtime_hours' => 8]);

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/items')
            ->assertOk()
            ->assertJsonPath('data.0.overtimePay', 80000);
    }
}
