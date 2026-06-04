<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\PayrollAdjustment;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollAdjustmentTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    private function seedEmployee(): Employee
    {
        $outlet = Outlet::query()->create([
            'name' => 'Adj Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'adj-out',
        ]);

        return Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-ADJ-01',
            'full_name' => 'Adj Worker',
            'position' => 'Staff',
            'base_salary' => 4000000,
            'status' => 'active',
        ]);
    }

    public function test_create_approve_and_cancel(): void
    {
        $this->actingAsHrmApiAdministrator();
        $employee = $this->seedEmployee();

        $create = $this->postJson('/api/v1/payroll-adjustments', [
            'employeeId' => $employee->id,
            'type' => 'earning',
            'category' => 'bonus',
            'amount' => 500000,
            'effectiveFrom' => '2026-10-01',
            'effectiveTo' => '2026-10-31',
            'description' => 'October bonus',
        ])->assertCreated();

        $id = (int) $create->json('data.id');
        $this->assertSame('draft', $create->json('data.status'));
        $this->assertStringContainsString('ADJ-', $create->json('data.adjustmentNo'));

        $this->patchJson('/api/v1/payroll-adjustments/'.$id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->patchJson('/api/v1/payroll-adjustments/'.$id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_deduction_adjustment_create(): void
    {
        $this->actingAsHrmApiAdministrator();
        $employee = $this->seedEmployee();

        $this->postJson('/api/v1/payroll-adjustments', [
            'employeeId' => $employee->id,
            'type' => 'deduction',
            'category' => 'penalty',
            'amount' => 150000,
            'effectiveFrom' => '2026-10-01',
            'effectiveTo' => '2026-10-31',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'deduction');
    }

    public function test_update_only_when_draft(): void
    {
        $this->actingAsHrmApiAdministrator();
        $employee = $this->seedEmployee();

        $create = $this->postJson('/api/v1/payroll-adjustments', [
            'employeeId' => $employee->id,
            'type' => 'earning',
            'category' => 'incentive',
            'amount' => 200000,
            'effectiveFrom' => '2026-10-01',
            'effectiveTo' => '2026-10-31',
        ])->assertCreated();

        $id = (int) $create->json('data.id');

        $this->patchJson('/api/v1/payroll-adjustments/'.$id, [
            'amount' => 250000,
        ])->assertOk()
            ->assertJsonPath('data.amount', 250000);

        $this->patchJson('/api/v1/payroll-adjustments/'.$id.'/approve')->assertOk();

        $this->patchJson('/api/v1/payroll-adjustments/'.$id, [
            'amount' => 300000,
        ])->assertStatus(422);
    }

    public function test_applicable_adjustments_exclude_cancelled(): void
    {
        $employee = $this->seedEmployee();

        PayrollAdjustment::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'adjustment_no' => 'ADJ-T-01',
            'type' => PayrollAdjustment::TYPE_EARNING,
            'category' => PayrollAdjustment::CATEGORY_COMMISSION,
            'amount' => 100000,
            'effective_from' => '2026-10-01',
            'effective_to' => '2026-10-31',
            'status' => PayrollAdjustment::STATUS_APPROVED,
        ]);

        PayrollAdjustment::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'adjustment_no' => 'ADJ-T-02',
            'type' => PayrollAdjustment::TYPE_EARNING,
            'category' => PayrollAdjustment::CATEGORY_BONUS,
            'amount' => 50000,
            'effective_from' => '2026-10-01',
            'effective_to' => '2026-10-31',
            'status' => PayrollAdjustment::STATUS_CANCELLED,
        ]);

        $service = app(\App\Modules\HR\Services\PayrollAdjustmentService::class);
        $totals = $service->totalsForEmployeeInPeriod(
            (int) $employee->id,
            '2026-10-01',
            '2026-10-31',
        );

        $this->assertEquals(100000.0, $totals['adjustmentEarning']);
        $this->assertEquals(0.0, $totals['adjustmentDeduction']);
    }
}
