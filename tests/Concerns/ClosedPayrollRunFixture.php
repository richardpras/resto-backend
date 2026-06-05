<?php

namespace Tests\Concerns;

use App\Models\Modules\HR\Domain\PayrollRunV2;

trait ClosedPayrollRunFixture
{
    use FinalizedPayrollRunFixture;

    /**
     * @return array{0: \App\Models\Modules\HR\Domain\Employee, 1: \App\Models\Modules\HR\Domain\PayrollPreparationPeriod, 2: PayrollRunV2}
     */
    protected function seedClosedPayrollRun(): array
    {
        [$employee, $period, $run] = $this->seedFinalizedPayrollRun();
        $runId = (int) $run->id;

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/start-payment')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/mark-paid')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/close')->assertOk();

        return [$employee, $period, PayrollRunV2::query()->findOrFail($runId)];
    }
}
