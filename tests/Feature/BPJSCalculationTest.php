<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\BpjsConfig;
use App\Models\Modules\HR\Domain\BpjsProfile;
use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\TestCase;

class BPJSCalculationTest extends TestCase
{
    use HrmApiFixture;
    use LockedPayrollPreparationFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->actingAsHrmApiAdministrator();
    }

    public function test_bpjs_employee_deductions_in_total_deductions_employer_not_in_net(): void
    {
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        BpjsConfig::query()->create([
            'effective_date' => '2026-01-01',
            'kesehatan_employee_rate' => 1,
            'kesehatan_company_rate' => 4,
            'jht_employee_rate' => 2,
            'jht_company_rate' => 3.7,
            'jp_employee_rate' => 1,
            'jp_company_rate' => 2,
            'jkk_company_rate' => 0.24,
            'jkm_company_rate' => 0.3,
            'status' => BpjsConfig::STATUS_ACTIVE,
        ]);

        BpjsProfile::query()->create([
            'employee_id' => $employee->id,
            'bpjs_kesehatan_enabled' => true,
            'bpjs_tk_enabled' => true,
        ]);

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

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertNotNull($item);

        $base = 5500000.0;
        $this->assertEquals(round($base * 0.01, 2), (float) $item->bpjs_kesehatan_employee);
        $this->assertEquals(round($base * 0.04, 2), (float) $item->bpjs_kesehatan_company);
        $this->assertEquals(round($base * 0.02, 2), (float) $item->bpjs_jht_employee);
        $this->assertEquals(round($base * 0.037, 2), (float) $item->bpjs_jht_company);
        $this->assertEquals(round($base * 0.01, 2), (float) $item->bpjs_jp_employee);
        $this->assertEquals(round($base * 0.02, 2), (float) $item->bpjs_jp_company);
        $this->assertEquals(round($base * 0.0024, 2), (float) $item->bpjs_jkk_company);
        $this->assertEquals(round($base * 0.003, 2), (float) $item->bpjs_jkm_company);

        $bpjsEmployeeTotal = 220000.0;
        $this->assertEquals(5500000.0, (float) $item->gross_salary);
        $this->assertEquals(100000.0 + $bpjsEmployeeTotal, (float) $item->total_deductions);
        $this->assertEquals(5500000.0 - 100000.0 - $bpjsEmployeeTotal, (float) $item->net_salary);

        $journalCountBefore = \Illuminate\Support\Facades\DB::table('journal_entries')->count();
        $this->assertSame($journalCountBefore, \Illuminate\Support\Facades\DB::table('journal_entries')->count());
    }

    public function test_no_bpjs_when_profile_disabled(): void
    {
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        BpjsConfig::query()->create([
            'effective_date' => '2026-01-01',
            'status' => BpjsConfig::STATUS_ACTIVE,
        ]);

        BpjsProfile::query()->create([
            'employee_id' => $employee->id,
            'bpjs_kesehatan_enabled' => false,
            'bpjs_tk_enabled' => false,
        ]);

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

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(0.0, (float) $item->bpjs_kesehatan_employee);
        $this->assertEquals(0.0, (float) $item->bpjs_jkk_company);
    }
}
