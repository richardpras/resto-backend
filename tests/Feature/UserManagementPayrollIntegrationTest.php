<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

class UserManagementPayrollIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_user_with_role_permission_can_post_payroll_and_create_balanced_journal(): void
    {
        $this->seedPayrollAccounts();

        $permission = $this->postJson('/api/v1/permissions', [
            'code' => 'payroll.create',
            'name' => 'Create Payroll',
        ])->assertCreated();

        $role = $this->postJson('/api/v1/roles', [
            'name' => 'hr-manager',
        ])->assertCreated();

        $this->postJson('/api/v1/roles/'.$role->json('data.id').'/permissions', [
            'permissionIds' => [$permission->json('data.id')],
        ])->assertOk();

        $user = $this->postJson('/api/v1/users', [
            'name' => 'HR Admin',
            'email' => 'hr-admin@example.com',
            'password' => 'secret123',
        ])->assertCreated();

        $this->postJson('/api/v1/users/'.$user->json('data.id').'/roles', [
            'roleIds' => [$role->json('data.id')],
        ])->assertOk();

        Passport::actingAs(User::query()->findOrFail((int) $user->json('data.id')));

        $employee = Employee::query()->create([
            'user_id' => (int) $user->json('data.id'),
            'employee_no' => 'EMP-001',
            'full_name' => 'HR Admin',
            'position' => 'HR Manager',
            'base_salary' => 10000000,
        ]);

        $payroll = $this->postJson('/api/v1/payrolls', [
            'employeeId' => $employee->id,
            'periodStart' => '2026-04-01',
            'periodEnd' => '2026-04-30',
            'adjustmentAmount' => 500000,
            'deductionAmount' => 250000,
            'cashAccountCode' => '1001',
            'salaryExpenseAccountCode' => '5001',
        ])->assertCreated();

        $this->assertEquals(10250000.0, (float) $payroll->json('data.netAmount'));
        $journalId = (int) $payroll->json('data.journalId');
        $totalDebit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('debit');
        $totalCredit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('credit');
        $this->assertSame($totalDebit, $totalCredit);
    }

    public function test_payroll_posting_applies_attendance_derived_inputs_and_returns_snapshot(): void
    {
        $this->seedPayrollAccounts();

        $permission = $this->postJson('/api/v1/permissions', [
            'code' => 'payroll.create',
            'name' => 'Create Payroll',
        ])->assertCreated();

        $role = $this->postJson('/api/v1/roles', [
            'name' => 'hr-manager-attendance',
        ])->assertCreated();

        $this->postJson('/api/v1/roles/'.$role->json('data.id').'/permissions', [
            'permissionIds' => [$permission->json('data.id')],
        ])->assertOk();

        $user = $this->postJson('/api/v1/users', [
            'name' => 'HR Attendance',
            'email' => 'hr-attendance@example.com',
            'password' => 'secret123',
        ])->assertCreated();

        $this->postJson('/api/v1/users/'.$user->json('data.id').'/roles', [
            'roleIds' => [$role->json('data.id')],
        ])->assertOk();

        $shift = Shift::query()->create([
            'tenant_id' => 1,
            'code' => 'SHIFT-PAY-ATT-01',
            'name' => 'Payroll Shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'late_tolerance_minutes' => 10,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        $employee = Employee::query()->create([
            'user_id' => (int) $user->json('data.id'),
            'employee_no' => 'EMP-ATT-PAY-001',
            'full_name' => 'HR Attendance',
            'position' => 'HR Staff',
            'base_salary' => 5000000,
        ]);

        Attendance::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'attendance_date' => '2026-04-10',
            'check_in' => '2026-04-10 08:30:00',
            'check_out' => '2026-04-10 17:00:00',
            'source' => 'manual',
            'status' => 'late',
            'sync_key' => 'payroll-late-20260410',
        ]);
        Attendance::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'attendance_date' => '2026-04-11',
            'check_in' => null,
            'check_out' => null,
            'source' => 'manual',
            'status' => 'absent',
            'sync_key' => 'payroll-absent-20260411',
        ]);
        Attendance::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'attendance_date' => '2026-04-12',
            'check_in' => '2026-04-12 08:00:00',
            'check_out' => '2026-04-12 17:45:00',
            'source' => 'manual',
            'status' => 'present',
            'sync_key' => 'payroll-overtime-20260412',
        ]);

        Passport::actingAs(User::query()->findOrFail((int) $user->json('data.id')));

        $payroll = $this->postJson('/api/v1/payrolls', [
            'employeeId' => $employee->id,
            'periodStart' => '2026-04-01',
            'periodEnd' => '2026-04-30',
            'adjustmentAmount' => 100000,
            'deductionAmount' => 50000,
            'lateDeductionPerCount' => 10000,
            'absentDeductionPerCount' => 50000,
            'overtimeAdjustmentPerMinute' => 1000,
            'cashAccountCode' => '1001',
            'salaryExpenseAccountCode' => '5001',
        ])->assertCreated();

        $this->assertEquals(5035000.0, (float) $payroll->json('data.netAmount'));
        $this->assertSame(1, (int) $payroll->json('data.attendanceSummary.lateCount'));
        $this->assertSame(1, (int) $payroll->json('data.attendanceSummary.absentCount'));
        $this->assertSame(45, (int) $payroll->json('data.attendanceSummary.overtimeMinutes'));
        $this->assertEquals(45000.0, (float) $payroll->json('data.attendanceSummary.derivedAdjustmentAmount'));
        $this->assertEquals(60000.0, (float) $payroll->json('data.attendanceSummary.derivedDeductionAmount'));

        $journalId = (int) $payroll->json('data.journalId');
        $totalDebit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('debit');
        $totalCredit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('credit');
        $this->assertSame($totalDebit, $totalCredit);
    }

    public function test_payroll_endpoint_requires_passport_token(): void
    {
        $employee = Employee::query()->create([
            'employee_no' => 'EMP-003',
            'full_name' => 'Worker 2',
            'position' => 'Cashier',
            'base_salary' => 4200000,
        ]);

        $this->postJson('/api/v1/payrolls', [
            'employeeId' => $employee->id,
            'periodStart' => '2026-04-01',
            'periodEnd' => '2026-04-30',
        ])->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_create_payroll(): void
    {
        $user = $this->postJson('/api/v1/users', [
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'password' => 'secret123',
        ])->assertCreated();

        $employee = Employee::query()->create([
            'employee_no' => 'EMP-002',
            'full_name' => 'Worker',
            'position' => 'Cashier',
            'base_salary' => 4000000,
        ]);

        Passport::actingAs(User::query()->findOrFail((int) $user->json('data.id')));

        $this->postJson('/api/v1/payrolls', [
            'employeeId' => $employee->id,
            'periodStart' => '2026-04-01',
            'periodEnd' => '2026-04-30',
        ])->assertForbidden();
    }

    private function seedPayrollAccounts(): void
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
    }
}
