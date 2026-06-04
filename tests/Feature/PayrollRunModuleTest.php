<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Adjustment;
use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Loan;
use App\Models\Modules\HR\Domain\Overtime;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollRunModuleTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_payroll_run_calculation_matches_template_logic(): void
    {
        $this->authenticate();

        $employee = Employee::query()->create([
            'employee_no' => 'E1',
            'full_name' => 'Andi Wijaya',
            'position' => 'Cashier',
            'outlet' => 'Main',
            'salary_type' => 'monthly',
            'base_salary' => 5000000,
            'overtime_rate' => 30000,
            'status' => 'active',
        ]);

        $shift = Shift::query()->create([
            'code' => 'SHIFT-0800-1700',
            'name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'late_tolerance_minutes' => 0,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        Attendance::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'attendance_date' => '2026-04-01',
            'check_in' => '2026-04-01 08:02:00',
            'check_out' => '2026-04-01 17:10:00',
            'source' => 'manual',
            'status' => 'present',
            'sync_key' => 'apr-1',
        ]);
        Attendance::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'attendance_date' => '2026-04-02',
            'check_in' => '2026-04-02 08:30:00',
            'check_out' => '2026-04-02 17:00:00',
            'source' => 'manual',
            'status' => 'late',
            'sync_key' => 'apr-2',
        ]);
        Attendance::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => null,
            'attendance_date' => '2026-03-15',
            'check_in' => '2026-03-15 08:00:00',
            'check_out' => '2026-03-15 17:00:00',
            'source' => 'manual',
            'status' => 'present',
            'sync_key' => 'mar-15',
        ]);

        Overtime::query()->create([
            'employee_id' => $employee->id,
            'date' => '2026-04-03',
            'hours' => 1.5,
            'status' => 'approved',
        ]);
        Overtime::query()->create([
            'employee_id' => $employee->id,
            'date' => '2026-04-04',
            'hours' => 2,
            'status' => 'pending',
        ]);

        Adjustment::query()->create([
            'employee_id' => $employee->id,
            'type' => 'allowance',
            'category' => 'bonus',
            'amount' => 200000,
            'date' => '2026-04-02',
        ]);
        Adjustment::query()->create([
            'employee_id' => $employee->id,
            'type' => 'deduction',
            'category' => 'lateness',
            'amount' => 100000,
            'date' => '2026-04-02',
        ]);

        Loan::query()->create([
            'employee_id' => $employee->id,
            'amount' => 1200000,
            'installments' => 6,
            'paid_installments' => 2,
            'start_date' => '2026-02-01',
            'status' => 'active',
        ]);
        Loan::query()->create([
            'employee_id' => $employee->id,
            'amount' => 9999999,
            'installments' => 1,
            'paid_installments' => 0,
            'start_date' => '2026-04-01',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/payroll/run', [
            'period' => '2026-04',
            'outlet' => 'Main',
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonCount(1, 'data.lines');
        $response->assertJsonPath('data.lines.0.employeeId', (int) $employee->id);
        $response->assertJsonPath('data.lines.0.baseSalary', 5000000);
        $response->assertJsonPath('data.lines.0.attendanceAdjustment', -4545455);
        $response->assertJsonPath('data.lines.0.overtimePay', 45000);
        $response->assertJsonPath('data.lines.0.allowances', 200000);
        $response->assertJsonPath('data.lines.0.deductions', 100000);
        $response->assertJsonPath('data.lines.0.loanDeduction', 200000);
        $response->assertJsonPath('data.lines.0.taxableIncome', 699545);
        $response->assertJsonPath('data.lines.0.pph21', 0);
        $response->assertJsonPath('data.lines.0.netSalary', 399545);
        $response->assertJsonPath('data.lines.0.workingDays', 22);
        $response->assertJsonPath('data.lines.0.presentDays', 2);
        $response->assertJsonPath('data.lines.0.overtimeHours', 1.5);
    }

    public function test_payroll_run_prevents_duplicate_period_and_outlet(): void
    {
        $this->authenticate();

        Employee::query()->create([
            'employee_no' => 'E1',
            'full_name' => 'Andi Wijaya',
            'position' => 'Cashier',
            'outlet' => 'Main',
            'salary_type' => 'monthly',
            'base_salary' => 5000000,
            'overtime_rate' => 30000,
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/payroll/run', [
            'period' => '2026-04',
            'outlet' => 'Main',
        ])->assertCreated();

        $this->postJson('/api/v1/payroll/run', [
            'period' => '2026-04',
            'outlet' => 'Main',
        ])->assertStatus(422);
    }

    public function test_payroll_run_sets_zero_loan_deduction_when_installments_is_not_positive(): void
    {
        $this->authenticate();

        $employee = Employee::query()->create([
            'employee_no' => 'E1',
            'full_name' => 'Andi Wijaya',
            'position' => 'Cashier',
            'outlet' => 'Main',
            'salary_type' => 'monthly',
            'base_salary' => 5000000,
            'overtime_rate' => 30000,
            'status' => 'active',
        ]);

        Loan::query()->create([
            'employee_id' => $employee->id,
            'amount' => 1000000,
            'installments' => 0,
            'paid_installments' => 0,
            'start_date' => '2026-03-01',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/payroll/run', [
            'period' => '2026-04',
            'outlet' => 'Main',
        ])->assertCreated();

        $response->assertJsonPath('data.lines.0.loanDeduction', 0);
    }

    public function test_post_journal_posts_run_lines_to_accounting_entries(): void
    {
        $this->authenticate();

        DB::table('accounts')->insert([
            ['code' => '1001', 'name' => 'Cash', 'type' => 'asset', 'created_at' => now(), 'updated_at' => now()],
            ['code' => '5001', 'name' => 'Salary Expense', 'type' => 'expense', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Employee::query()->create([
            'employee_no' => 'E1',
            'full_name' => 'Andi Wijaya',
            'position' => 'Cashier',
            'outlet' => 'Main',
            'salary_type' => 'monthly',
            'base_salary' => 5000000,
            'overtime_rate' => 30000,
            'status' => 'active',
        ]);

        $run = $this->postJson('/api/v1/payroll/run', [
            'period' => '2026-04',
            'outlet' => 'Main',
        ])->assertCreated();

        $this->postJson('/api/v1/payroll/'.$run->json('data.id').'/post-journal')
            ->assertOk()
            ->assertJsonPath('message', 'Payroll run posted to journal successfully.');

        $this->assertSame(1, DB::table('journals')->where('source_type', 'payroll_run')->count());
        $this->assertSame(2, DB::table('journal_entries')->count());
    }

    public function test_loan_update_rejects_paid_installments_greater_than_installments(): void
    {
        $this->authenticate();

        $employee = Employee::query()->create([
            'employee_no' => 'E2',
            'full_name' => 'Siti Rahma',
            'position' => 'Chef',
            'outlet' => 'Main',
            'salary_type' => 'monthly',
            'base_salary' => 7000000,
            'overtime_rate' => 40000,
            'status' => 'active',
        ]);

        $loan = Loan::query()->create([
            'employee_id' => $employee->id,
            'amount' => 3000000,
            'installments' => 6,
            'paid_installments' => 1,
            'start_date' => '2026-04-01',
            'status' => 'active',
        ]);

        $this->patchJson('/api/v1/loans/'.$loan->id, [
            'paidInstallments' => 7,
        ])->assertStatus(422);
    }

    public function test_payroll_pay_creates_loan_payment_log_and_updates_installment_progress(): void
    {
        $this->authenticate();

        $employee = Employee::query()->create([
            'employee_no' => 'E3',
            'full_name' => 'Budi Santoso',
            'position' => 'Waiter',
            'outlet' => 'Main',
            'salary_type' => 'monthly',
            'base_salary' => 5000000,
            'overtime_rate' => 25000,
            'status' => 'active',
        ]);

        $loan = Loan::query()->create([
            'employee_id' => $employee->id,
            'amount' => 1200000,
            'installments' => 6,
            'paid_installments' => 2,
            'start_date' => '2026-02-01',
            'status' => 'active',
        ]);

        $runResponse = $this->postJson('/api/v1/payroll/run', [
            'period' => '2026-04',
            'outlet' => 'Main',
        ])->assertCreated();

        $runId = (int) $runResponse->json('data.id');
        $lineId = (int) DB::table('payroll_lines')->where('payroll_run_id', $runId)->value('id');
        $this->postJson('/api/v1/payroll-lines/'.$lineId.'/lock')->assertOk();

        $this->postJson('/api/v1/payroll/'.$runId.'/pay')
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $loan->refresh();
        $this->assertSame(3, (int) $loan->paid_installments);
        $this->assertSame('active', (string) $loan->status);

        $this->assertDatabaseHas('loan_payments', [
            'loan_id' => $loan->id,
            'payroll_run_id' => $runId,
            'installment_no' => 3,
            'amount' => 200000,
        ]);
    }

    public function test_payroll_line_can_be_locked_and_unlocked_without_executing_payment(): void
    {
        $this->authenticate();

        $employee = Employee::query()->create([
            'employee_no' => 'E4',
            'full_name' => 'Rina Putri',
            'position' => 'Cashier',
            'outlet' => 'Main',
            'salary_type' => 'monthly',
            'base_salary' => 4500000,
            'overtime_rate' => 25000,
            'status' => 'active',
        ]);

        Loan::query()->create([
            'employee_id' => $employee->id,
            'amount' => 900000,
            'installments' => 3,
            'paid_installments' => 0,
            'start_date' => '2026-03-01',
            'status' => 'active',
        ]);

        $runResponse = $this->postJson('/api/v1/payroll/run', [
            'period' => '2026-04',
            'outlet' => 'Main',
        ])->assertCreated();
        $runId = (int) $runResponse->json('data.id');

        $lineId = (int) DB::table('payroll_lines')->where('payroll_run_id', $runId)->value('id');

        $this->postJson('/api/v1/payroll-lines/'.$lineId.'/lock')->assertOk();
        $this->assertDatabaseHas('payroll_lines', [
            'id' => $lineId,
            'payment_status' => 'locked',
        ]);
        $this->assertSame(0, DB::table('loan_payments')->count());

        $this->postJson('/api/v1/payroll-lines/'.$lineId.'/unlock')->assertOk();
        $this->assertDatabaseHas('payroll_lines', [
            'id' => $lineId,
            'payment_status' => 'unlocked',
        ]);
        $this->assertSame(0, DB::table('loan_payments')->count());
    }

    public function test_payroll_run_pay_processes_only_locked_lines_and_keeps_partial_run_processed(): void
    {
        $this->authenticate();

        $employeeA = Employee::query()->create([
            'employee_no' => 'E5',
            'full_name' => 'Locked Employee',
            'position' => 'Waiter',
            'outlet' => 'Main',
            'salary_type' => 'monthly',
            'base_salary' => 4000000,
            'overtime_rate' => 20000,
            'status' => 'active',
        ]);
        $employeeB = Employee::query()->create([
            'employee_no' => 'E6',
            'full_name' => 'Unlocked Employee',
            'position' => 'Chef',
            'outlet' => 'Main',
            'salary_type' => 'monthly',
            'base_salary' => 6000000,
            'overtime_rate' => 35000,
            'status' => 'active',
        ]);

        $loanA = Loan::query()->create([
            'employee_id' => $employeeA->id,
            'amount' => 1200000,
            'installments' => 6,
            'paid_installments' => 0,
            'start_date' => '2026-03-01',
            'status' => 'active',
        ]);
        $loanB = Loan::query()->create([
            'employee_id' => $employeeB->id,
            'amount' => 600000,
            'installments' => 3,
            'paid_installments' => 0,
            'start_date' => '2026-03-01',
            'status' => 'active',
        ]);

        $runResponse = $this->postJson('/api/v1/payroll/run', [
            'period' => '2026-04',
            'outlet' => 'Main',
        ])->assertCreated();
        $runId = (int) $runResponse->json('data.id');

        $lockedLineId = (int) DB::table('payroll_lines')
            ->where('payroll_run_id', $runId)
            ->where('employee_id', $employeeA->id)
            ->value('id');
        $this->postJson('/api/v1/payroll-lines/'.$lockedLineId.'/lock')->assertOk();

        $this->postJson('/api/v1/payroll/'.$runId.'/pay')
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $loanA->refresh();
        $loanB->refresh();
        $this->assertSame(1, (int) $loanA->paid_installments);
        $this->assertSame(0, (int) $loanB->paid_installments);
        $this->assertDatabaseHas('loan_payments', [
            'loan_id' => $loanA->id,
            'payroll_run_id' => $runId,
        ]);
        $this->assertDatabaseMissing('loan_payments', [
            'loan_id' => $loanB->id,
            'payroll_run_id' => $runId,
        ]);
    }

    public function test_payroll_run_uses_employee_eligibility_and_prorates_monthly_for_termination_in_period(): void
    {
        $this->authenticate();

        $eligible = Employee::query()->create([
            'employee_no' => 'E7',
            'full_name' => 'Eligible Terminated',
            'position' => 'Admin',
            'outlet' => 'Main',
            'salary_type' => 'monthly',
            'base_salary' => 4400000,
            'overtime_rate' => 20000,
            'hire_date' => '2025-01-01',
            'termination_date' => '2026-04-10',
            'status' => 'inactive',
        ]);
        Employee::query()->create([
            'employee_no' => 'E8',
            'full_name' => 'Excluded Before Period',
            'position' => 'Admin',
            'outlet' => 'Main',
            'salary_type' => 'monthly',
            'base_salary' => 4400000,
            'overtime_rate' => 20000,
            'hire_date' => '2025-01-01',
            'termination_date' => '2026-03-20',
            'status' => 'inactive',
        ]);

        Adjustment::query()->create([
            'employee_id' => $eligible->id,
            'type' => 'allowance',
            'category' => 'meal',
            'amount' => 300000,
            'date' => '2026-04-25',
        ]);
        Adjustment::query()->create([
            'employee_id' => $eligible->id,
            'type' => 'allowance',
            'category' => 'meal',
            'amount' => 120000,
            'date' => '2026-04-05',
        ]);

        $response = $this->postJson('/api/v1/payroll/run', [
            'period' => '2026-04',
            'outlet' => 'Main',
        ])->assertCreated();

        $response->assertJsonCount(1, 'data.lines');
        $response->assertJsonPath('data.lines.0.employeeId', (int) $eligible->id);
        $response->assertJsonPath('data.lines.0.baseSalary', 1600000);
        $response->assertJsonPath('data.lines.0.allowances', 120000);
    }

    private function authenticate(): void
    {
        $this->actingAsHrmApiAdministrator();
    }
}
