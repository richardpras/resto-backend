<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\AttendanceAuditLog;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollAttendanceFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_attendance_and_audit_log_schema_and_relations_are_available(): void
    {
        $employee = Employee::create([
            'employee_no' => 'EMP-ATT-001',
            'full_name' => 'Attendance Worker',
            'position' => 'Cashier',
            'base_salary' => 4500000,
        ]);

        $shift = Shift::create([
            'tenant_id' => 1,
            'code' => 'SHIFT-MORNING',
            'name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'late_tolerance_minutes' => 10,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'attendance_date' => '2026-04-30',
            'check_in' => '2026-04-30 08:07:00',
            'check_out' => '2026-04-30 17:03:00',
            'source' => 'manual',
            'status' => 'present',
            'sync_key' => 'manual-emp-att-001-2026-04-30',
            'notes' => 'Initial manual input',
        ]);

        $auditLog = AttendanceAuditLog::create([
            'attendance_id' => $attendance->id,
            'actor_user_id' => null,
            'action' => 'manual-correction',
            'before_json' => ['status' => 'late'],
            'after_json' => ['status' => 'present'],
            'reason' => 'Check-in machine outage',
            'source_type' => 'manual-edit',
        ]);

        $this->assertTrue($employee->attendances()->whereKey($attendance->id)->exists());
        $this->assertTrue($employee->shifts()->whereKey($shift->id)->exists());
        $this->assertTrue($shift->attendances()->whereKey($attendance->id)->exists());
        $this->assertTrue($attendance->auditLogs()->whereKey($auditLog->id)->exists());
    }
}
