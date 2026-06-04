<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeLeaveBalance;
use App\Models\Modules\HR\Domain\LeaveType;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class LeaveBalanceTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_manual_allocation_and_list(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        $this->patchJson('/api/v1/employees/'.$employee->id.'/leave-balances', [
            'balances' => [
                ['leaveTypeId' => $type->id, 'allocatedDays' => 12],
            ],
        ])->assertOk()
            ->assertJsonPath('data.0.allocatedDays', 12)
            ->assertJsonPath('data.0.remainingDays', 12);

        $this->getJson('/api/v1/hr/employees/'.$employee->id.'/leave-balances')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_allocated_cannot_be_less_than_used(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        EmployeeLeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'allocated_days' => 10,
            'used_days' => 5,
            'remaining_days' => 5,
        ]);

        $this->patchJson('/api/v1/employees/'.$employee->id.'/leave-balances', [
            'balances' => [
                ['leaveTypeId' => $type->id, 'allocatedDays' => 3],
            ],
        ])->assertStatus(422);
    }

    /**
     * @return array{0: Employee, 1: LeaveType}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Bal Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'bal-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-BAL-01',
            'full_name' => 'Balance Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $type = LeaveType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'annual_leave',
            'name' => 'Annual',
            'deduct_leave_balance' => true,
            'is_active' => true,
        ]);

        return [$employee, $type];
    }
}
