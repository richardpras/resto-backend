<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeCashAdvance;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class CashAdvanceTest extends TestCase
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
     * @return array{0: Employee}
     */
    private function seedEmployee(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Cash Adv Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'cadv-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-CADV-01',
            'full_name' => 'Advance Worker',
            'position' => 'Staff',
            'base_salary' => 4000000,
            'status' => 'active',
        ]);

        return [$employee];
    }

    public function test_next_payroll_workflow(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee] = $this->seedEmployee();

        $create = $this->postJson('/api/v1/cash-advances', [
            'employeeId' => $employee->id,
            'amount' => 1500000,
            'repaymentType' => 'next_payroll',
        ])->assertCreated();

        $id = (int) $create->json('data.id');
        $this->assertSame('next_payroll', $create->json('data.repaymentType'));

        $this->patchJson('/api/v1/cash-advances/'.$id.'/approve')->assertOk();
        EmployeeCashAdvance::query()->whereKey($id)->update(['approved_at' => '2026-09-15']);
        $this->patchJson('/api/v1/cash-advances/'.$id.'/activate')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $advance = EmployeeCashAdvance::query()->findOrFail($id);
        $this->assertSame(1, $advance->installments()->count());
    }

    public function test_installment_workflow(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee] = $this->seedEmployee();

        $create = $this->postJson('/api/v1/cash-advances', [
            'employeeId' => $employee->id,
            'amount' => 3000000,
            'repaymentType' => 'installment',
            'installmentCount' => 3,
            'installmentAmount' => 1000000,
        ])->assertCreated();

        $id = (int) $create->json('data.id');
        $this->patchJson('/api/v1/cash-advances/'.$id.'/approve')->assertOk();
        EmployeeCashAdvance::query()->whereKey($id)->update(['approved_at' => '2026-09-01']);
        $this->patchJson('/api/v1/cash-advances/'.$id.'/activate')->assertOk();

        $this->assertSame(3, EmployeeCashAdvance::query()->findOrFail($id)->installments()->count());
    }

    public function test_cancel_pending_advance(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee] = $this->seedEmployee();

        $create = $this->postJson('/api/v1/cash-advances', [
            'employeeId' => $employee->id,
            'amount' => 500000,
            'repaymentType' => 'next_payroll',
        ])->assertCreated();

        $id = (int) $create->json('data.id');

        $this->patchJson('/api/v1/cash-advances/'.$id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }
}
