<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\BpjsConfig;
use App\Models\Modules\HR\Domain\BpjsProfile;
use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollPayslip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\Concerns\RendersPendingPayslips;
use Tests\TestCase;

class BPJSPayslipTest extends TestCase
{
    use HrmApiFixture;
    use LockedPayrollPreparationFixture;
    use RefreshDatabase;
    use RendersPendingPayslips;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        Storage::fake('local');
        $this->actingAsHrmApiAdministrator();
    }

    public function test_payslip_breakdown_includes_bpjs_and_pdf_mentions_employer_section(): void
    {
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();
        $this->grantHrmApiUserOutletAccess((int) $period->outlet_id);

        BpjsConfig::query()->create([
            'effective_date' => '2026-01-01',
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
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/approve')->assertOk();
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/finalize')->assertOk();

        $this->postJson('/api/v1/payslips/generate', ['payrollRunId' => $runId])->assertCreated();
        $this->renderPendingPayslipsForRun($runId);

        $payslip = PayrollPayslip::query()->where('payroll_run_id', $runId)->first();
        $calc = $payslip->breakdown_json['calculation'] ?? [];
        $bpjs = $calc['bpjs'] ?? [];

        $this->assertGreaterThan(0, (float) ($bpjs['bpjs_kesehatan_employee'] ?? 0));
        $this->assertGreaterThan(0, (float) ($bpjs['bpjs_kesehatan_company'] ?? 0));
        $this->assertGreaterThan(0, (float) ($bpjs['bpjs_jkk_company'] ?? 0));

        $this->assertNotNull($payslip->pdf_path);
        Storage::disk('local')->assertExists($payslip->pdf_path);

        $html = view('payslips.pdf', array_merge(
            ['payslipNo' => $payslip->payslip_no],
            $payslip->breakdown_json,
            [
                'basicSalary' => (float) ($calc['basicSalary'] ?? 0),
                'allowance' => (float) ($calc['allowance'] ?? 0),
                'overtimePay' => (float) ($calc['overtimePay'] ?? 0),
                'adjustmentEarning' => (float) ($calc['adjustmentEarning'] ?? 0),
                'defaultDeduction' => (float) ($calc['defaultDeduction'] ?? 0),
                'unpaidLeaveDeduction' => (float) ($calc['unpaidLeaveDeduction'] ?? 0),
                'attendanceDeduction' => (float) ($calc['attendanceDeduction'] ?? 0),
                'loanDeduction' => (float) ($calc['loanDeduction'] ?? 0),
                'cashAdvanceDeduction' => (float) ($calc['cashAdvanceDeduction'] ?? 0),
                'adjustmentDeduction' => (float) ($calc['adjustmentDeduction'] ?? 0),
                'bpjsKesehatanEmployee' => (float) $bpjs['bpjs_kesehatan_employee'],
                'bpjsJhtEmployee' => (float) ($bpjs['bpjs_jht_employee'] ?? 0),
                'bpjsJpEmployee' => (float) ($bpjs['bpjs_jp_employee'] ?? 0),
                'bpjsKesehatanCompany' => (float) $bpjs['bpjs_kesehatan_company'],
                'bpjsJhtCompany' => (float) ($bpjs['bpjs_jht_company'] ?? 0),
                'bpjsJpCompany' => (float) ($bpjs['bpjs_jp_company'] ?? 0),
                'bpjsJkkCompany' => (float) ($bpjs['bpjs_jkk_company'] ?? 0),
                'bpjsJkmCompany' => (float) ($bpjs['bpjs_jkm_company'] ?? 0),
                'pph21Amount' => 0,
                'ptkpStatus' => '',
                'annualPkp' => 0,
                'annualPph21' => 0,
                'grossSalary' => (float) $payslip->gross_salary,
                'totalDeductions' => (float) $payslip->total_deductions,
                'netSalary' => (float) $payslip->net_salary,
            ],
        ))->render();

        $this->assertStringContainsString('BPJS Kesehatan', $html);
        $this->assertStringContainsString('Employer Contributions', $html);
        $this->assertStringContainsString('JKK', $html);
    }
}
