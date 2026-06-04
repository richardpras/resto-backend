<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeLoan;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class EmployeeLoanTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    /**
     * @return array{0: Employee, 1: Outlet}
     */
    private function seedEmployee(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Loan Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'loan-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-LOAN-01',
            'full_name' => 'Loan Borrower',
            'position' => 'Staff',
            'base_salary' => 4000000,
            'status' => 'active',
        ]);

        return [$employee, $outlet];
    }

    public function test_create_approve_activate_workflow(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee] = $this->seedEmployee();

        $create = $this->postJson('/api/v1/employee-loans', [
            'employeeId' => $employee->id,
            'principalAmount' => 12000000,
            'installmentAmount' => 1000000,
            'totalInstallments' => 12,
        ])->assertCreated();

        $loanId = (int) $create->json('data.id');
        $this->assertSame('pending', $create->json('data.status'));
        $this->assertStringContainsString('LOAN-', $create->json('data.loanNo'));

        $this->patchJson('/api/v1/employee-loans/'.$loanId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->patchJson('/api/v1/employee-loans/'.$loanId.'/activate')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $loan = EmployeeLoan::query()->findOrFail($loanId);
        $this->assertSame(12, $loan->installments()->count());
        $this->assertEquals(12000000.0, (float) $loan->remaining_balance);
    }

    public function test_cancel_pending_loan(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee] = $this->seedEmployee();

        $create = $this->postJson('/api/v1/employee-loans', [
            'employeeId' => $employee->id,
            'principalAmount' => 5000000,
            'installmentAmount' => 500000,
            'totalInstallments' => 10,
        ])->assertCreated();

        $loanId = (int) $create->json('data.id');

        $this->patchJson('/api/v1/employee-loans/'.$loanId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_update_only_when_pending(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee] = $this->seedEmployee();

        $create = $this->postJson('/api/v1/employee-loans', [
            'employeeId' => $employee->id,
            'principalAmount' => 3000000,
            'installmentAmount' => 300000,
            'totalInstallments' => 10,
        ])->assertCreated();

        $loanId = (int) $create->json('data.id');

        $this->patchJson('/api/v1/employee-loans/'.$loanId, [
            'principalAmount' => 3500000,
        ])->assertOk()
            ->assertJsonPath('data.principalAmount', 3500000);

        $this->patchJson('/api/v1/employee-loans/'.$loanId.'/approve')->assertOk();

        $this->patchJson('/api/v1/employee-loans/'.$loanId, [
            'principalAmount' => 4000000,
        ])->assertStatus(422);
    }
}
