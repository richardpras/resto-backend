<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\AttendanceAuditLog;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PayrollAttendanceFlowTest extends TestCase
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

    public function test_shift_crud_routes_require_authentication_and_work_for_authenticated_user(): void
    {
        $this->authenticateUser('shift-auth@example.com');

        $created = $this->postJson('/api/v1/shifts', [
            'tenantId' => 1,
            'code' => 'SHIFT-AUTH-01',
            'name' => 'Auth Shift',
            'startTime' => '08:00',
            'endTime' => '17:00',
            'lateToleranceMinutes' => 5,
            'overtimeAfterMinutes' => 30,
            'active' => true,
        ])->assertCreated();

        $this->getJson('/api/v1/shifts')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'SHIFT-AUTH-01');

        $this->putJson('/api/v1/shifts/'.$created->json('data.id'), [
            'tenantId' => 1,
            'code' => 'SHIFT-AUTH-01',
            'name' => 'Updated Auth Shift',
            'startTime' => '09:00',
            'endTime' => '18:00',
            'lateToleranceMinutes' => 15,
            'overtimeAfterMinutes' => 40,
            'active' => true,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Auth Shift')
            ->assertJsonPath('data.startTime', '09:00:00');
    }

    public function test_attendance_sync_endpoint_is_idempotent_by_external_ref(): void
    {
        $this->authenticateUser('sync-auth@example.com');

        $employee = $this->postJson('/api/v1/hr/employees', [
            'employeeNo' => 'EMP-SYNC-001',
            'fullName' => 'Sync Worker',
            'position' => 'Cashier',
            'baseSalary' => 4200000,
        ])->assertCreated();

        $shift = $this->postJson('/api/v1/shifts', [
            'tenantId' => 1,
            'code' => 'SHIFT-SYNC-01',
            'name' => 'Sync Shift',
            'startTime' => '08:00',
            'endTime' => '17:00',
            'lateToleranceMinutes' => 10,
            'overtimeAfterMinutes' => 0,
        ])->assertCreated();

        $payload = [
            'source' => 'fingerprint-device',
            'externalRef' => 'device-A-2026-04-30-EMP-SYNC-001-IN',
            'employeeId' => $employee->json('data.id'),
            'shiftId' => $shift->json('data.id'),
            'attendanceDate' => '2026-04-30',
            'checkIn' => '2026-04-30 08:03:00',
            'checkOut' => '2026-04-30 17:01:00',
            'syncKey' => 'sync-EMP-SYNC-001-2026-04-30',
        ];

        $this->postJson('/api/v1/attendances/sync', $payload)
            ->assertCreated()
            ->assertJsonPath('duplicate', false);

        $this->postJson('/api/v1/attendances/sync', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(1, DB::table('attendance_sync_logs')->count());
    }

    public function test_manual_correction_creates_audit_log_with_before_after_and_reason(): void
    {
        $this->authenticateUser('manual-auth@example.com');

        $employee = $this->postJson('/api/v1/hr/employees', [
            'employeeNo' => 'EMP-MANUAL-001',
            'fullName' => 'Manual Worker',
            'position' => 'Cashier',
            'baseSalary' => 3800000,
        ])->assertCreated();

        $attendance = Attendance::query()->create([
            'employee_id' => $employee->json('data.id'),
            'shift_id' => null,
            'attendance_date' => '2026-04-30',
            'check_in' => '2026-04-30 08:30:00',
            'check_out' => '2026-04-30 17:00:00',
            'source' => 'fingerprint',
            'status' => 'late',
            'sync_key' => 'sync-manual-correction-1',
            'notes' => 'Auto sync',
        ]);

        $this->postJson('/api/v1/attendances/'.$attendance->id.'/manual-correction', [
            'checkIn' => '2026-04-30 08:00:00',
            'status' => 'present',
            'notes' => 'Machine delayed upload',
            'reason' => 'Fingerprint machine network lag',
        ])->assertOk()
            ->assertJsonPath('data.status', 'present');

        $auditLog = AttendanceAuditLog::query()->latest('id')->first();
        $this->assertNotNull($auditLog);
        $this->assertSame('manual-correction', $auditLog->action);
        $this->assertSame('manual-edit', $auditLog->source_type);
        $this->assertSame('Fingerprint machine network lag', $auditLog->reason);
        $this->assertSame('late', $auditLog->before_json['status']);
        $this->assertSame('present', $auditLog->after_json['status']);
        $this->assertNotNull($auditLog->actor_user_id);
    }

    public function test_payroll_response_contains_attendance_summary_snapshot_and_balanced_journal(): void
    {
        DB::table('accounts')->insert([
            [
                'code' => '1001',
                'name' => 'Cash',
                'type' => 'asset',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '5001',
                'name' => 'Salary Expense',
                'type' => 'expense',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAsHrmApiAdministrator();

        $employee = $this->postJson('/api/v1/hr/employees', [
            'employeeNo' => 'EMP-PAY-FLOW-001',
            'fullName' => 'Payroll Flow User',
            'position' => 'Cashier',
            'baseSalary' => 4000000,
        ])->assertCreated();

        $shift = $this->postJson('/api/v1/shifts', [
            'tenantId' => 1,
            'code' => 'SHIFT-PAY-FLOW-01',
            'name' => 'Payroll Flow Shift',
            'startTime' => '08:00',
            'endTime' => '17:00',
            'lateToleranceMinutes' => 10,
            'overtimeAfterMinutes' => 0,
            'active' => true,
        ])->assertCreated();

        Attendance::query()->create([
            'employee_id' => $employee->json('data.id'),
            'shift_id' => $shift->json('data.id'),
            'attendance_date' => '2026-04-05',
            'check_in' => '2026-04-05 08:20:00',
            'check_out' => '2026-04-05 17:00:00',
            'source' => 'manual',
            'status' => 'late',
            'sync_key' => 'flow-late-20260405',
        ]);
        Attendance::query()->create([
            'employee_id' => $employee->json('data.id'),
            'shift_id' => $shift->json('data.id'),
            'attendance_date' => '2026-04-06',
            'check_in' => null,
            'check_out' => null,
            'source' => 'manual',
            'status' => 'absent',
            'sync_key' => 'flow-absent-20260406',
        ]);

        $payroll = $this->postJson('/api/v1/payrolls', [
            'employeeId' => $employee->json('data.id'),
            'periodStart' => '2026-04-01',
            'periodEnd' => '2026-04-30',
            'lateDeductionPerCount' => 25000,
            'absentDeductionPerCount' => 100000,
            'cashAccountCode' => '1001',
            'salaryExpenseAccountCode' => '5001',
        ])->assertCreated();

        $this->assertSame(1, (int) $payroll->json('data.attendanceSummary.lateCount'));
        $this->assertSame(1, (int) $payroll->json('data.attendanceSummary.absentCount'));
        $this->assertSame(0, (int) $payroll->json('data.attendanceSummary.overtimeMinutes'));
        $this->assertEquals(125000.0, (float) $payroll->json('data.attendanceSummary.derivedDeductionAmount'));
        $this->assertEquals(3875000.0, (float) $payroll->json('data.netAmount'));

        $journalId = (int) $payroll->json('data.journalId');
        $totalDebit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('debit');
        $totalCredit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('credit');
        $this->assertSame($totalDebit, $totalCredit);
    }

    private function authenticateUser(string $email): User
    {
        return $this->actingAsHrmApiAdministrator();
    }
}
