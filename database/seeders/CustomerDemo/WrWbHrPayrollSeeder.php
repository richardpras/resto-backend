<?php

namespace Database\Seeders\CustomerDemo;

use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeCashAdvance;
use App\Models\Modules\HR\Domain\EmployeeCashAdvanceInstallment;
use App\Models\Modules\HR\Domain\EmployeeLoan;
use App\Models\Modules\HR\Domain\EmployeeLoanInstallment;
use App\Models\Modules\HR\Domain\EmployeeReimbursement;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\EmployeeShiftAssignment;
use App\Models\Modules\HR\Domain\LeaveRequest;
use App\Models\Modules\HR\Domain\LeaveType;
use App\Models\Modules\HR\Domain\OvertimeRequest;
use App\Models\Modules\HR\Domain\OvertimeType;
use App\Models\Modules\HR\Domain\PayrollAdjustment;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\UserManagement\Domain\Department;
use App\Models\Modules\UserManagement\Domain\Position;
use App\Models\User;
use App\Modules\HR\Services\AttendancePeriodService;
use App\Modules\HR\Services\PayrollClosingService;
use App\Modules\HR\Services\PayrollPostingService;
use App\Modules\HR\Services\PayrollPreparationPeriodService;
use App\Modules\HR\Services\PayrollRunServiceV2;
use Carbon\CarbonImmutable;
use Database\Seeders\CustomerDemo\Support\CustomerDemoPayrollBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WrWbHrPayrollSeeder extends Seeder
{
    /** @var array<string, Employee> */
    private array $employees = [];

    public function run(): void
    {
        $manager = CustomerDemoContext::user('manager');
        $outletId = CustomerDemoContext::outletId();

        DB::transaction(function () use ($manager, $outletId): void {
            [$deptOps, $deptKitchen] = $this->seedDepartments($outletId);
            $positions = $this->seedPositions($outletId, $deptOps, $deptKitchen);
            $shifts = $this->seedShifts();
            $this->seedEmployees($outletId, $positions, $shifts);
            $this->seedAttendanceMay($outletId);
            $this->seedPayrollComponents($manager);
            $this->seedPayrollRun($manager);
        });
    }

    /** @return array{0: Department, 1: Department} */
    private function seedDepartments(int $outletId): array
    {
        $ops = Department::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'code' => 'OPS'],
            ['name' => 'Operasional', 'is_active' => true],
        );
        $kitchen = Department::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'code' => 'DAPUR'],
            ['name' => 'Dapur', 'is_active' => true],
        );

        return [$ops, $kitchen];
    }

    /** @return array<string, Position> */
    private function seedPositions(int $outletId, Department $ops, Department $kitchen): array
    {
        $specs = [
            'manager' => ['name' => 'Manager', 'dept' => $ops],
            'kasir' => ['name' => 'Kasir', 'dept' => $ops],
            'cook' => ['name' => 'Cook', 'dept' => $kitchen],
            'helper' => ['name' => 'Kitchen Helper', 'dept' => $kitchen],
        ];

        $positions = [];
        foreach ($specs as $key => $row) {
            $positions[$key] = Position::query()->updateOrCreate(
                ['outlet_id' => $outletId, 'code' => strtoupper($key)],
                ['name' => $row['name'], 'department_id' => $row['dept']->id, 'is_active' => true],
            );
        }

        return $positions;
    }

    /** @return array{0: Shift, 1: Shift} */
    private function seedShifts(): array
    {
        $morning = Shift::query()->where('start_time', '07:00:00')->where('end_time', '15:00:00')->first()
            ?? Shift::query()->updateOrCreate(
                ['code' => 'WRWB-PAGI'],
                ['name' => 'Shift Pagi', 'start_time' => '07:00:00', 'end_time' => '15:00:00', 'active' => true],
            );
        $evening = Shift::query()->where('start_time', '15:00:00')->where('end_time', '23:00:00')->first()
            ?? Shift::query()->updateOrCreate(
                ['code' => 'WRWB-SORE'],
                ['name' => 'Shift Sore', 'start_time' => '15:00:00', 'end_time' => '23:00:00', 'active' => true],
            );

        return [$morning, $evening];
    }

    /** @param array<string, Position> $positions */
    private function seedEmployees(int $outletId, array $positions, array $shifts): void
    {
        [$morning, $evening] = $shifts;

        $blueprint = [
            'manager' => ['name' => 'Manager WR WB', 'no' => 'WRWB-EMP-01', 'user' => 'manager', 'pos' => 'manager', 'dept' => 'OPS', 'salary' => 9500000, 'shift' => $morning],
            'kasir1' => ['name' => 'Kasir A', 'no' => 'WRWB-EMP-02', 'user' => 'kasir1', 'pos' => 'kasir', 'dept' => 'OPS', 'salary' => 4200000, 'shift' => $morning],
            'kasir2' => ['name' => 'Kasir B', 'no' => 'WRWB-EMP-03', 'user' => 'kasir2', 'pos' => 'kasir', 'dept' => 'OPS', 'salary' => 4200000, 'shift' => $evening],
            'cook' => ['name' => 'Cook WR WB', 'no' => 'WRWB-EMP-04', 'user' => 'kitchen', 'pos' => 'cook', 'dept' => 'DAPUR', 'salary' => 5500000, 'shift' => $morning],
            'helper' => ['name' => 'Kitchen Helper', 'no' => 'WRWB-EMP-05', 'user' => null, 'pos' => 'helper', 'dept' => 'DAPUR', 'salary' => 3800000, 'shift' => $evening],
        ];

        foreach ($blueprint as $key => $row) {
            $userId = $row['user'] !== null ? CustomerDemoContext::user($row['user'])->id : null;
            $employee = Employee::query()->updateOrCreate(
                ['employee_no' => $row['no']],
                [
                    'user_id' => $userId,
                    'tenant_id' => CustomerDemoContext::TENANT_ID,
                    'outlet_id' => $outletId,
                    'full_name' => $row['name'],
                    'position' => $positions[$row['pos']]->name,
                    'position_id' => $positions[$row['pos']]->id,
                    'department_id' => Department::query()->where('outlet_id', $outletId)->where('code', $row['dept'])->value('id'),
                    'outlet' => CustomerDemoContext::OUTLET_NAME,
                    'salary_type' => 'monthly',
                    'base_salary' => $row['salary'],
                    'overtime_rate' => 30000,
                    'hire_date' => '2025-01-15',
                    'status' => 'active',
                ],
            );

            EmployeeSalaryProfile::query()->updateOrCreate(
                ['employee_id' => $employee->id],
                [
                    'basic_salary' => $row['salary'],
                    'default_allowance' => 300000,
                    'default_deduction' => 100000,
                    'overtime_rate_type' => EmployeeSalaryProfile::OVERTIME_RATE_FIXED_HOURLY,
                    'overtime_rate_value' => 35000,
                ],
            );

            EmployeeShiftAssignment::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'shift_id' => $row['shift']->id],
                [
                    'outlet_id' => $outletId,
                    'effective_from' => CustomerDemoContext::PERIOD_START,
                    'is_active' => true,
                ],
            );

            for ($day = 1; $day <= 31; $day++) {
                $date = CustomerDemoContext::date($day)->toDateString();
                EmployeeRoster::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'roster_date' => $date],
                    [
                        'outlet_id' => $outletId,
                        'shift_id' => $row['shift']->id,
                        'status' => 'published',
                    ],
                );
            }

            $this->employees[$key] = $employee;
        }
    }

    private function seedAttendanceMay(int $outletId): void
    {
        $leaveType = LeaveType::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'code' => 'CUTI-THN'],
            ['name' => 'Cuti Tahunan', 'paid_leave' => true, 'is_active' => true],
        );

        LeaveType::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'code' => 'SAKIT'],
            ['name' => 'Sakit', 'paid_leave' => false, 'is_active' => true],
        );

        for ($day = 1; $day <= 31; $day++) {
            $date = CustomerDemoContext::date($day)->toDateString();

            foreach ($this->employees as $key => $employee) {
                $status = AttendanceRecord::STATUS_PRESENT;
                $clockIn = "{$date} 07:30:00";
                $clockOut = "{$date} 15:30:00";

                if ($key === 'kasir2' && $day === 12) {
                    $status = AttendanceRecord::STATUS_ABSENT;
                    $clockIn = null;
                    $clockOut = null;
                } elseif ($key === 'helper' && $day === 20) {
                    continue;
                } elseif ($key === 'kasir1' && $day % 9 === 0) {
                    $status = AttendanceRecord::STATUS_LATE;
                    $clockIn = "{$date} 08:20:00";
                }

                AttendanceRecord::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'attendance_date' => $date],
                    [
                        'outlet_id' => $outletId,
                        'clock_in' => $clockIn,
                        'clock_out' => $clockOut,
                        'source' => AttendanceRecord::SOURCE_MANUAL,
                        'status' => $status,
                        'notes' => 'WR WB demo attendance',
                    ],
                );
            }
        }

        LeaveRequest::query()->updateOrCreate(
            ['employee_id' => $this->employees['helper']->id, 'start_date' => '2026-05-20'],
            [
                'outlet_id' => $outletId,
                'leave_type_id' => $leaveType->id,
                'end_date' => '2026-05-20',
                'total_days' => 1,
                'status' => 'approved',
                'approved_at' => CustomerDemoContext::date(19),
            ],
        );

        $overtimeType = OvertimeType::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'code' => 'OT-WEEKDAY'],
            ['name' => 'Lembur Hari Kerja', 'multiplier' => 1.5, 'is_active' => true],
        );

        OvertimeRequest::query()->updateOrCreate(
            ['employee_id' => $this->employees['cook']->id, 'overtime_date' => '2026-05-25'],
            [
                'outlet_id' => $outletId,
                'overtime_type_id' => $overtimeType->id,
                'start_time' => '15:00:00',
                'end_time' => '23:00:00',
                'total_minutes' => 480,
                'total_hours' => 8,
                'status' => 'approved',
                'approved_at' => CustomerDemoContext::date(24),
            ],
        );
    }

    private function seedPayrollComponents(User $manager): void
    {
        $kasir1 = $this->employees['kasir1'];
        $kasir2 = $this->employees['kasir2'];
        $cook = $this->employees['cook'];
        $helper = $this->employees['helper'];
        $mgr = $this->employees['manager'];

        $advance = EmployeeCashAdvance::query()->updateOrCreate(
            ['advance_no' => 'WRWB-CADV-01'],
            [
                'outlet_id' => CustomerDemoContext::outletId(),
                'employee_id' => $kasir1->id,
                'amount' => 500000,
                'repayment_type' => EmployeeCashAdvance::REPAYMENT_INSTALLMENT,
                'installment_count' => 1,
                'installment_amount' => 500000,
                'deducted_amount' => 0,
                'remaining_amount' => 500000,
                'status' => EmployeeCashAdvance::STATUS_ACTIVE,
                'approved_at' => '2026-04-15',
                'approved_by' => $manager->id,
            ],
        );
        EmployeeCashAdvanceInstallment::query()->updateOrCreate(
            ['cash_advance_id' => $advance->id, 'installment_no' => 1],
            ['due_date' => CustomerDemoContext::PERIOD_END, 'amount' => 500000, 'status' => EmployeeCashAdvanceInstallment::STATUS_UNPAID],
        );

        $loan = EmployeeLoan::query()->updateOrCreate(
            ['loan_no' => 'WRWB-LOAN-01'],
            [
                'outlet_id' => CustomerDemoContext::outletId(),
                'employee_id' => $kasir2->id,
                'principal_amount' => 1200000,
                'installment_amount' => 300000,
                'total_installments' => 4,
                'paid_installments' => 0,
                'remaining_balance' => 1200000,
                'status' => EmployeeLoan::STATUS_ACTIVE,
                'approved_at' => '2026-04-01',
                'approved_by' => $manager->id,
            ],
        );
        EmployeeLoanInstallment::query()->updateOrCreate(
            ['loan_id' => $loan->id, 'installment_no' => 1],
            ['due_date' => '2026-05-15', 'amount' => 300000, 'status' => EmployeeLoanInstallment::STATUS_UNPAID],
        );

        EmployeeReimbursement::query()->updateOrCreate(
            ['claim_no' => 'WRWB-REIMB-01'],
            [
                'outlet_id' => CustomerDemoContext::outletId(),
                'employee_id' => $helper->id,
                'category' => 'transport',
                'title' => 'Reimburse transport Mei',
                'claim_amount' => 150000,
                'expense_date' => CustomerDemoContext::date(8)->toDateString(),
                'description' => 'Transport Mei',
                'status' => 'approved',
                'approved_at' => CustomerDemoContext::date(10),
                'approved_by' => $manager->id,
            ],
        );

        PayrollAdjustment::query()->updateOrCreate(
            ['employee_id' => $mgr->id, 'adjustment_no' => 'WRWB-ADJ-01'],
            [
                'outlet_id' => CustomerDemoContext::outletId(),
                'type' => 'bonus',
                'category' => 'performance',
                'amount' => 1000000,
                'effective_from' => CustomerDemoContext::PERIOD_START,
                'effective_to' => CustomerDemoContext::PERIOD_END,
                'status' => 'approved',
                'approved_by' => $manager->id,
                'description' => 'Bonus kinerja Mei',
            ],
        );
    }

    private function seedPayrollRun(User $manager): void
    {
        app(CustomerDemoPayrollBuilder::class)->buildPostedRun(
            $manager,
            CustomerDemoContext::outletId(),
            CustomerDemoContext::PERIOD_START,
            CustomerDemoContext::PERIOD_END,
            array_values($this->employees),
        );
    }
}
