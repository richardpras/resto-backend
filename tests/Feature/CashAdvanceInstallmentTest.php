<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeCashAdvance;
use App\Models\Modules\HR\Domain\EmployeeCashAdvanceInstallment;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\HR\Services\CashAdvanceDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class CashAdvanceInstallmentTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_next_payroll_single_installment_schedule(): void
    {
        $this->actingAsHrmApiAdministrator();

        $outlet = Outlet::query()->create([
            'name' => 'NP Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'np-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-NP',
            'full_name' => 'NP Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $create = $this->postJson('/api/v1/cash-advances', [
            'employeeId' => $employee->id,
            'amount' => 800000,
            'repaymentType' => 'next_payroll',
        ])->assertCreated();

        $id = (int) $create->json('data.id');
        $this->patchJson('/api/v1/cash-advances/'.$id.'/approve')->assertOk();
        EmployeeCashAdvance::query()->whereKey($id)->update(['approved_at' => '2026-09-10']);
        $this->patchJson('/api/v1/cash-advances/'.$id.'/activate')->assertOk();

        $rows = $this->getJson('/api/v1/cash-advances/'.$id.'/installments')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals(800000.0, (float) $rows[0]['amount']);
        $this->assertSame('2026-10-31', $rows[0]['dueDate']);
    }

    public function test_deduction_service_read_only(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'Ded CA Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ded-ca',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-CA-DED',
            'full_name' => 'Ded CA Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $advance = EmployeeCashAdvance::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'advance_no' => 'CADV-TEST-01',
            'amount' => 600000,
            'repayment_type' => EmployeeCashAdvance::REPAYMENT_INSTALLMENT,
            'installment_count' => 2,
            'installment_amount' => 300000,
            'deducted_amount' => 0,
            'remaining_amount' => 600000,
            'status' => EmployeeCashAdvance::STATUS_ACTIVE,
            'approved_at' => '2026-09-01',
        ]);

        EmployeeCashAdvanceInstallment::query()->create([
            'cash_advance_id' => $advance->id,
            'installment_no' => 1,
            'due_date' => '2026-10-12',
            'amount' => 300000,
            'status' => EmployeeCashAdvanceInstallment::STATUS_UNPAID,
        ]);

        $service = app(CashAdvanceDeductionService::class);
        $preview = $service->deductionForEmployeeInPeriod(
            (int) $employee->id,
            '2026-10-01',
            '2026-10-31',
        );

        $this->assertEquals(300000.0, $preview['cashAdvanceDeduction']);
        $this->assertEquals(600000.0, $preview['remainingBalance']);
    }
}
