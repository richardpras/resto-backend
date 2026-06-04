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

class PayrollRunWorkflowTest extends TestCase
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

    public function test_create_calculate_approve_finalize_workflow(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 3000000,
            'default_allowance' => 0,
            'default_deduction' => 0,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'attended_days' => 15,
            'overtime_hours' => 0,
            'review_required' => false,
        ]);

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $runId = (int) $runRes->json('data.id');

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')
            ->assertOk()
            ->assertJsonPath('data.status', 'calculated');

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertStatus(422);

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/finalize')
            ->assertOk()
            ->assertJsonPath('data.status', 'finalized');

        $run = PayrollRunV2::query()->find($runId);
        $this->assertNotNull($run->finalized_at);

        $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/items')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
