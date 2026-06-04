<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeLeaveBalance;
use App\Models\Modules\HR\Domain\LeaveRequest;
use App\Models\Modules\HR\Domain\LeaveType;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\HR\Services\LeaveRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_request_calculates_total_days(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        $this->postJson('/api/v1/leave-requests', [
            'employeeId' => $employee->id,
            'leaveTypeId' => $type->id,
            'startDate' => '2026-09-01',
            'endDate' => '2026-09-03',
            'reason' => 'Family trip',
        ])->assertCreated()
            ->assertJsonPath('data.totalDays', 3)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_overlap_with_approved_leave_rejected(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        LeaveRequest::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-12',
            'total_days' => 3,
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $this->postJson('/api/v1/leave-requests', [
            'employeeId' => $employee->id,
            'leaveTypeId' => $type->id,
            'startDate' => '2026-09-11',
            'endDate' => '2026-09-15',
        ])->assertStatus(422);
    }

    public function test_total_days_calculation_service(): void
    {
        $days = app(LeaveRequestService::class)->calculateTotalDays('2026-09-01', '2026-09-01');
        $this->assertSame(1, $days);

        $days = app(LeaveRequestService::class)->calculateTotalDays('2026-09-01', '2026-09-07');
        $this->assertSame(7, $days);
    }

    /**
     * @return array{0: Employee, 1: LeaveType}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Req Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'req-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-LV-01',
            'full_name' => 'Leave Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $type = LeaveType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'annual_leave',
            'name' => 'Annual',
            'deduct_leave_balance' => false,
            'is_active' => true,
        ]);

        return [$employee, $type];
    }
}
