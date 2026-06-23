<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\EmployeeTaxProfile;
use App\Models\Modules\HR\Domain\PayrollPayslip;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Modules\HR\Services\Pph21ConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\Concerns\RendersPendingPayslips;
use Tests\TestCase;

class Pph21PayslipTest extends TestCase
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

    public function test_payslip_includes_pph21_breakdown_and_tax_section(): void
    {
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();
        $this->grantHrmApiUserOutletAccess((int) $period->outlet_id);

        app(Pph21ConfigurationService::class)->create([
            'effectiveDate' => '2026-01-01',
            'isActive' => true,
            'brackets' => app(Pph21ConfigurationService::class)->defaultBrackets(),
        ]);

        EmployeeTaxProfile::query()->create([
            'employee_id' => $employee->id,
            'ptkp_status' => 'TK0',
            'pph21_enabled' => true,
        ]);

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 10000000,
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
        $pph21 = $calc['pph21'] ?? [];

        $this->assertGreaterThan(0, (float) ($pph21['pph21_amount'] ?? 0));
        $this->assertSame('TK0', $pph21['ptkp_status'] ?? null);
        Storage::disk('local')->assertExists($payslip->pdf_path);

        $html = view('payslips.pdf', [
            'payslipNo' => $payslip->payslip_no,
            'companyName' => 'Test',
            'outletName' => 'Outlet',
            'periodLabel' => '2026-10',
            'generatedAt' => now()->toDateTimeString(),
            'employeeNo' => 'EMP',
            'employeeName' => 'Worker',
            'position' => 'Staff',
            'basicSalary' => (float) ($calc['basicSalary'] ?? 0),
            'allowance' => 0,
            'overtimePay' => 0,
            'adjustmentEarning' => 0,
            'defaultDeduction' => 0,
            'unpaidLeaveDeduction' => 0,
            'attendanceDeduction' => 0,
            'loanDeduction' => 0,
            'cashAdvanceDeduction' => 0,
            'adjustmentDeduction' => 0,
            'bpjsKesehatanEmployee' => 0,
            'bpjsJhtEmployee' => 0,
            'bpjsJpEmployee' => 0,
            'bpjsKesehatanCompany' => 0,
            'bpjsJhtCompany' => 0,
            'bpjsJpCompany' => 0,
            'bpjsJkkCompany' => 0,
            'bpjsJkmCompany' => 0,
            'pph21Amount' => (float) $pph21['pph21_amount'],
            'ptkpStatus' => (string) $pph21['ptkp_status'],
            'annualPkp' => (float) $pph21['annual_pkp'],
            'annualPph21' => (float) $pph21['annual_pph21'],
            'grossSalary' => (float) $payslip->gross_salary,
            'totalDeductions' => (float) $payslip->total_deductions,
            'netSalary' => (float) $payslip->net_salary,
        ])->render();

        $this->assertStringContainsString('PPh21', $html);
        $this->assertStringContainsString('Annual PKP', $html);
        $this->assertStringContainsString('TK0', $html);
    }
}
