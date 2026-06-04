<?php

namespace Tests\Concerns;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunV2;

trait FinalizedPayrollRunFixture
{
    use LockedPayrollPreparationFixture;

    /**
     * @return array{0: Employee, 1: PayrollPreparationPeriod, 2: PayrollRunV2}
     */
    protected function seedFinalizedPayrollRun(): array
    {
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 5000000,
            'default_allowance' => 500000,
            'default_deduction' => 100000,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'review_required' => false,
        ]);

        $this->actingAsHrmApiAdministrator();

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/approve')->assertOk();
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/finalize')->assertOk();

        $run = PayrollRunV2::query()->findOrFail($runId);

        return [$employee, $period, $run];
    }
}
