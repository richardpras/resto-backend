<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\LeaveRequest;
use App\Models\Modules\HR\Domain\LeaveType;
use App\Models\Modules\HR\Domain\OvertimeDailySummary;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollPreparationSnapshotTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_generate_consolidates_attendance_leave_overtime(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet, $leaveType] = $this->seedFixtures();

        AttendancePeriodLock::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-07',
            'status' => AttendancePeriodLock::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-10-02',
            'status' => 'published',
            'published_at' => now(),
        ]);

        AttendanceDailySummary::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-10-02',
            'clock_in' => '2026-10-02 08:00:00',
            'clock_out' => '2026-10-02 16:00:00',
            'worked_minutes' => 480,
            'late_minutes' => 15,
            'early_leave_minutes' => 0,
            'is_absent' => false,
            'is_incomplete' => false,
            'requires_review' => false,
            'attendance_status' => 'late',
        ]);

        AttendanceDailySummary::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-10-03',
            'is_absent' => true,
            'is_incomplete' => false,
            'requires_review' => false,
            'attendance_status' => 'absent',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        LeaveRequest::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-04',
            'end_date' => '2026-10-05',
            'total_days' => 2,
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        OvertimeDailySummary::query()->create([
            'employee_id' => $employee->id,
            'overtime_date' => '2026-10-06',
            'approved_minutes' => 120,
            'approved_hours' => 2,
            'request_count' => 1,
        ]);

        $periodRes = $this->postJson('/api/v1/payroll-preparation-periods', [
            'outletId' => $outlet->id,
            'periodStart' => '2026-10-01',
            'periodEnd' => '2026-10-07',
        ])->assertCreated();

        $periodId = (int) $periodRes->json('data.id');

        $this->postJson('/api/v1/payroll-preparation-periods/'.$periodId.'/generate')->assertOk();

        $snapshot = PayrollPreparationSnapshot::query()
            ->where('preparation_period_id', $periodId)
            ->where('employee_id', $employee->id)
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame(1, (int) $snapshot->scheduled_days);
        $this->assertSame(1, (int) $snapshot->attended_days);
        $this->assertSame(1, (int) $snapshot->absent_days);
        $this->assertSame(15, (int) $snapshot->late_minutes);
        $this->assertEquals(2.0, (float) $snapshot->leave_days);
        $this->assertEquals(2.0, (float) $snapshot->paid_leave_days);
        $this->assertSame(120, (int) $snapshot->overtime_minutes);
        $this->assertEquals(2.0, (float) $snapshot->overtime_hours);

        $this->getJson('/api/v1/payroll-preparation-periods/'.$periodId.'/snapshots')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * @return array{0: Employee, 1: Shift, 2: Outlet, 3: LeaveType}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Snap Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'snap-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-PREP-SNAP',
            'full_name' => 'Snap Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $shift = Shift::query()->create([
            'tenant_id' => 1,
            'code' => 'DAY',
            'name' => 'Day',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'late_tolerance_minutes' => 5,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        $leaveType = LeaveType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'annual',
            'name' => 'Annual',
            'requires_attachment' => false,
            'deduct_leave_balance' => false,
            'paid_leave' => true,
            'is_active' => true,
        ]);

        return [$employee, $shift, $outlet, $leaveType];
    }
}
