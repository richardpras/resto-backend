<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use App\Modules\HR\Services\PayrollCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\TestCase;

class PayrollOvertimeCalculationTest extends TestCase
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

    public function test_fixed_hourly_overtime_pay(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 5000000,
            'overtime_rate_type' => EmployeeSalaryProfile::OVERTIME_RATE_FIXED_HOURLY,
            'overtime_rate_value' => 25000,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'attended_days' => 20,
            'overtime_hours' => 10,
            'review_required' => false,
        ]);

        $runId = $this->createAndCalculateRun($period->id);

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(250000.0, (float) $item->overtime_pay);
        $this->assertEquals(5250000.0, (float) $item->gross_salary);
    }

    public function test_multiplier_hourly_overtime_pay(): void
    {
        $service = app(PayrollCalculationService::class);
        $profile = new EmployeeSalaryProfile([
            'overtime_rate_type' => EmployeeSalaryProfile::OVERTIME_RATE_MULTIPLIER_HOURLY,
            'overtime_rate_value' => 1.5,
        ]);

        $pay = $service->calculateOvertimePay(5190000, 12, $profile);
        $hourly = 5190000 / 173;
        $expected = round(12 * $hourly * 1.5, 2);
        $this->assertEquals($expected, $pay);
    }

    private function createAndCalculateRun(int $periodId): int
    {
        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $periodId,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        return $runId;
    }
}
