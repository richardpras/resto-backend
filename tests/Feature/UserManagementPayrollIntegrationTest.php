<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementPayrollIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_role_permission_can_post_payroll_and_create_balanced_journal(): void
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

        $employee = $this->postJson('/api/v1/employees', [
            'userId' => $user->json('data.id'),
            'employeeNo' => 'EMP-001',
            'fullName' => 'HR Admin',
            'position' => 'HR Manager',
            'baseSalary' => 10000000,
        ])->assertCreated();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'hr-admin@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $token = $login->json('data.accessToken');

        $payroll = $this->withToken($token)->postJson('/api/v1/payrolls', [
            'employeeId' => $employee->json('data.id'),
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

    public function test_payroll_endpoint_requires_passport_token(): void
    {
        $employee = $this->postJson('/api/v1/employees', [
            'employeeNo' => 'EMP-003',
            'fullName' => 'Worker 2',
            'position' => 'Cashier',
            'baseSalary' => 4200000,
        ])->assertCreated();

        $this->postJson('/api/v1/payrolls', [
            'employeeId' => $employee->json('data.id'),
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

        $employee = $this->postJson('/api/v1/employees', [
            'employeeNo' => 'EMP-002',
            'fullName' => 'Worker',
            'position' => 'Cashier',
            'baseSalary' => 4000000,
        ])->assertCreated();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'regular@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $token = $login->json('data.accessToken');

        $this->withToken($token)->postJson('/api/v1/payrolls', [
            'employeeId' => $employee->json('data.id'),
            'periodStart' => '2026-04-01',
            'periodEnd' => '2026-04-30',
        ])->assertForbidden();
    }
}
