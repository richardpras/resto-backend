<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeLeaveBalance;
use App\Models\Modules\HR\Domain\LeaveRequest;
use App\Models\Modules\HR\Domain\LeaveType;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class LeaveApprovalWorkflowTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_approve_reject_cancel_workflow(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        EmployeeLeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'allocated_days' => 10,
            'used_days' => 0,
            'remaining_days' => 10,
        ]);

        $create = $this->postJson('/api/v1/leave-requests', [
            'employeeId' => $employee->id,
            'leaveTypeId' => $type->id,
            'startDate' => '2026-10-01',
            'endDate' => '2026-10-02',
        ])->assertCreated();

        $id = (int) $create->json('data.id');

        $this->patchJson('/api/v1/leave-requests/'.$id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $balance = EmployeeLeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->first();
        $this->assertNotNull($balance);
        $this->assertEquals(2.0, (float) $balance->used_days);

        $rejectReq = $this->postJson('/api/v1/leave-requests', [
            'employeeId' => $employee->id,
            'leaveTypeId' => $type->id,
            'startDate' => '2026-11-01',
            'endDate' => '2026-11-01',
        ])->assertCreated();
        $rejectId = (int) $rejectReq->json('data.id');

        $this->patchJson('/api/v1/leave-requests/'.$rejectId.'/reject', [
            'rejectionReason' => 'Peak season',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->patchJson('/api/v1/leave-requests/'.$id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $balance->refresh();
        $this->assertEquals(0.0, (float) $balance->used_days);
    }

    public function test_approve_does_not_create_attendance_records(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures(deductBalance: false);

        $res = $this->postJson('/api/v1/leave-requests', [
            'employeeId' => $employee->id,
            'leaveTypeId' => $type->id,
            'startDate' => '2026-10-05',
            'endDate' => '2026-10-06',
        ])->assertCreated();

        $this->patchJson('/api/v1/leave-requests/'.$res->json('data.id').'/approve')->assertOk();

        $this->assertSame(0, AttendanceRecord::query()->where('employee_id', $employee->id)->count());
    }

    public function test_insufficient_balance_blocks_approval(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        EmployeeLeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'allocated_days' => 1,
            'used_days' => 0,
            'remaining_days' => 1,
        ]);

        $res = $this->postJson('/api/v1/leave-requests', [
            'employeeId' => $employee->id,
            'leaveTypeId' => $type->id,
            'startDate' => '2026-10-10',
            'endDate' => '2026-10-12',
        ])->assertCreated();

        $this->patchJson('/api/v1/leave-requests/'.$res->json('data.id').'/approve')->assertStatus(422);
    }

    /**
     * @return array{0: Employee, 1: LeaveType}
     */
    private function seedFixtures(bool $deductBalance = true): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Flow Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'flow-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-FLOW-01',
            'full_name' => 'Flow Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $type = LeaveType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'annual_leave',
            'name' => 'Annual',
            'deduct_leave_balance' => $deductBalance,
            'is_active' => true,
        ]);

        return [$employee, $type];
    }
}
