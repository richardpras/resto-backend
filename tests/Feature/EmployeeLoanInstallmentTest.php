<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeLoan;
use App\Models\Modules\HR\Domain\EmployeeLoanInstallment;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\HR\Services\EmployeeLoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class EmployeeLoanInstallmentTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_installment_schedule_generated_on_activate(): void
    {
        $this->actingAsHrmApiAdministrator();

        $outlet = Outlet::query()->create([
            'name' => 'Inst Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'inst-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-INST',
            'full_name' => 'Inst Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $create = $this->postJson('/api/v1/employee-loans', [
            'employeeId' => $employee->id,
            'principalAmount' => 6000000,
            'installmentAmount' => 1000000,
            'totalInstallments' => 6,
        ])->assertCreated();

        $loanId = (int) $create->json('data.id');
        $this->patchJson('/api/v1/employee-loans/'.$loanId.'/approve')->assertOk();
        $this->patchJson('/api/v1/employee-loans/'.$loanId.'/activate')->assertOk();

        $res = $this->getJson('/api/v1/employee-loans/'.$loanId.'/installments')->assertOk();
        $rows = $res->json('data');
        $this->assertCount(6, $rows);
        $this->assertSame(1, $rows[0]['installmentNo']);
        $this->assertEquals(1000000.0, (float) $rows[0]['amount']);
        $this->assertSame('unpaid', $rows[0]['status']);

        $this->assertNotEmpty($rows[0]['dueDate']);
    }

    public function test_loan_deduction_service_read_only_preview(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'Ded Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ded-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-DED',
            'full_name' => 'Ded Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $loan = EmployeeLoan::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'loan_no' => 'LOAN-TEST-01',
            'principal_amount' => 2000000,
            'installment_amount' => 500000,
            'total_installments' => 4,
            'paid_installments' => 0,
            'remaining_balance' => 2000000,
            'status' => EmployeeLoan::STATUS_ACTIVE,
            'approved_at' => '2026-10-01',
        ]);

        EmployeeLoanInstallment::query()->create([
            'loan_id' => $loan->id,
            'installment_no' => 1,
            'due_date' => '2026-10-15',
            'amount' => 500000,
            'status' => EmployeeLoanInstallment::STATUS_UNPAID,
        ]);

        $service = app(\App\Modules\HR\Services\LoanDeductionService::class);
        $preview = $service->deductionForEmployeeInPeriod(
            (int) $employee->id,
            '2026-10-01',
            '2026-10-31',
        );

        $this->assertEquals(500000.0, $preview['loanDeduction']);
        $this->assertEquals(2000000.0, $preview['remainingBalance']);
        $this->assertCount(1, $preview['installments']);

        $installment = EmployeeLoanInstallment::query()->first();
        $this->assertSame(EmployeeLoanInstallment::STATUS_UNPAID, $installment->status);
    }
}
