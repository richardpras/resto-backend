<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AttendanceRecordManualCreateTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_manual_create_persists_attendance_record_with_manual_source(): void
    {
        $admin = $this->actingAsHrmApiAdministrator();
        [$employee, , $outlet] = $this->seedFixtures();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $this->postJson('/api/v1/attendance', [
            'employeeId' => $employee->id,
            'date' => '2026-07-10',
            'clockIn' => '08:00',
            'clockOut' => '16:00',
            'notes' => 'Manual entry by HR',
        ])->assertCreated()
            ->assertJsonPath('data.source', AttendanceRecord::SOURCE_MANUAL)
            ->assertJsonPath('data.employeeId', $employee->id)
            ->assertJsonPath('data.notes', 'Manual entry by HR');

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-10',
            'source' => AttendanceRecord::SOURCE_MANUAL,
        ]);

        $this->assertDatabaseHas('attendance_daily_summaries', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-10',
        ]);
    }

    public function test_duplicate_manual_create_is_blocked(): void
    {
        $admin = $this->actingAsHrmApiAdministrator();
        [$employee] = $this->seedFixtures();
        $this->assignUserToOutlets($admin, [(int) $employee->outlet_id]);

        AttendanceRecord::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-11',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'source' => AttendanceRecord::SOURCE_CSV_IMPORT,
        ]);

        $this->postJson('/api/v1/attendance', [
            'employeeId' => $employee->id,
            'date' => '2026-07-11',
            'clockIn' => '08:00',
            'clockOut' => '16:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_manual_create_blocked_when_period_locked(): void
    {
        $admin = $this->actingAsHrmApiAdministrator();
        [$employee, , $outlet] = $this->seedFixtures();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        AttendancePeriodLock::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'locked',
            'locked_at' => now(),
        ]);

        $this->postJson('/api/v1/attendance', [
            'employeeId' => $employee->id,
            'date' => '2026-07-15',
            'clockIn' => '08:00',
            'clockOut' => '16:00',
        ])->assertForbidden();
    }

    /**
     * @return array{0: Employee, 1: Shift, 2: Outlet}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Manual Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'manual-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-'.uniqid('', true),
            'full_name' => 'Manual Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $shift = Shift::query()->create([
            'tenant_id' => 1,
            'code' => 'S1',
            'name' => 'Standard',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'late_tolerance_minutes' => 5,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        return [$employee, $shift, $outlet];
    }
}
