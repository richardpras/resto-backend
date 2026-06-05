<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\EmployeeTaxProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use App\Models\Modules\HR\Domain\Pph21Config;
use App\Modules\HR\Services\Pph21CalculationService;
use App\Modules\HR\Services\Pph21ConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\TestCase;

class Pph21CalculationTest extends TestCase
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

    public function test_progressive_tax_and_monthly_deduction(): void
    {
        $service = app(Pph21CalculationService::class);
        $configService = app(Pph21ConfigurationService::class);

        $configService->create([
            'effectiveDate' => '2026-01-01',
            'isActive' => true,
            'brackets' => $configService->defaultBrackets(),
        ]);

        $employee = $this->seedEmployee();

        $result = $service->calculateForEmployee((int) $employee->id, 10000000, 0, '2026-10-31');

        $this->assertEquals(0.0, (float) $result['pph21_amount']);

        EmployeeTaxProfile::query()->create([
            'employee_id' => $employee->id,
            'ptkp_status' => 'TK0',
            'pph21_enabled' => true,
        ]);

        $result = $service->calculateForEmployee((int) $employee->id, 10000000, 0, '2026-10-31');

        $this->assertEquals(120000000.0, (float) $result['annual_taxable_income']);
        $this->assertEquals(66000000.0, (float) $result['annual_pkp']);
        $this->assertEquals(3900000.0, (float) $result['annual_pph21']);
        $this->assertEquals(325000.0, (float) $result['pph21_amount']);
    }

    public function test_ptkp_reduces_pkp(): void
    {
        $configService = app(Pph21ConfigurationService::class);
        $configService->create([
            'effectiveDate' => '2026-01-01',
            'isActive' => true,
            'brackets' => $configService->defaultBrackets(),
        ]);

        $employee = $this->seedEmployee();

        EmployeeTaxProfile::query()->create([
            'employee_id' => $employee->id,
            'ptkp_status' => 'K1',
            'pph21_enabled' => true,
        ]);

        $result = app(Pph21CalculationService::class)->calculateForEmployee((int) $employee->id, 6000000, 0, '2026-10-31');

        $this->assertEquals(72000000.0, (float) $result['annual_taxable_income']);
        $this->assertEquals(9000000.0, (float) $result['annual_pkp']);
        $this->assertEquals(450000.0, (float) $result['annual_pph21']);
        $this->assertEquals(37500.0, (float) $result['pph21_amount']);
    }

    public function test_payroll_integration_includes_pph21_in_total_deductions(): void
    {
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

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
        $this->assertEquals(325000.0, (float) $item->pph21_amount);
        $this->assertEquals(10000000.0, (float) $item->gross_salary);
        $this->assertEquals(325000.0, (float) $item->total_deductions);
        $this->assertEquals(9675000.0, (float) $item->net_salary);

        $journalCountBefore = \Illuminate\Support\Facades\DB::table('journal_entries')->count();
        $this->assertSame($journalCountBefore, \Illuminate\Support\Facades\DB::table('journal_entries')->count());
    }

    public function test_no_pph21_when_disabled(): void
    {
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        Pph21Config::query()->create(['effective_date' => '2026-01-01', 'is_active' => true]);

        EmployeeTaxProfile::query()->create([
            'employee_id' => $employee->id,
            'ptkp_status' => 'TK0',
            'pph21_enabled' => false,
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

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertEquals(0.0, (float) $item->pph21_amount);
    }

    private function seedEmployee(): Employee
    {
        $outlet = Outlet::query()->create([
            'name' => 'PPh21 Unit',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pph21-unit',
        ]);

        return Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-PPH-'.uniqid(),
            'full_name' => 'PPh21 Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);
    }
}
