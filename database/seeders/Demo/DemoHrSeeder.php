<?php

namespace Database\Seeders\Demo;

use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\HR\Domain\PayrollRunAudit;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoHrSeeder extends Seeder
{
    /** @var list<array{role: string, name: string, salary_type: string, base: int}> */
    private const STAFF_BLUEPRINT = [
        ['role' => 'Manager', 'name' => 'Manager', 'salary_type' => 'monthly', 'base' => 9500000],
        ['role' => 'Cashier', 'name' => 'Cashier Morning', 'salary_type' => 'monthly', 'base' => 4200000],
        ['role' => 'Cashier', 'name' => 'Cashier Evening', 'salary_type' => 'monthly', 'base' => 4200000],
        ['role' => 'Kitchen', 'name' => 'Kitchen Morning', 'salary_type' => 'monthly', 'base' => 7800000],
        ['role' => 'Kitchen', 'name' => 'Kitchen Evening', 'salary_type' => 'monthly', 'base' => 7800000],
        ['role' => 'Server', 'name' => 'Server Alpha', 'salary_type' => 'daily', 'base' => 180000],
        ['role' => 'Server', 'name' => 'Server Beta', 'salary_type' => 'daily', 'base' => 180000],
        ['role' => 'Server', 'name' => 'Server Gamma', 'salary_type' => 'daily', 'base' => 180000],
    ];

    public function run(): void
    {
        $base = DemoSeederContext::baseTime();

        foreach (DemoSeederContext::outlets() as $outlet) {
            $key = DemoSeederContext::outletKeyFor($outlet) ?? 'A';
            $employeeIds = [];

            foreach (self::STAFF_BLUEPRINT as $index => $row) {
                $no = sprintf('DEMO-%s-%02d', $key, $index + 1);
                $employee = Employee::query()->updateOrCreate(
                    ['employee_no' => $no],
                    [
                        'tenant_id' => 1,
                        'full_name' => "{$row['name']} ({$outlet->name})",
                        'position' => $row['role'],
                        'outlet' => $outlet->name,
                        'salary_type' => $row['salary_type'],
                        'base_salary' => $row['base'],
                        'overtime_rate' => 30000,
                        'hire_date' => $base->subMonths(18 - $index)->toDateString(),
                        'status' => 'active',
                    ],
                );
                $employeeIds[] = $employee->id;
            }

            $this->seedAttendance($employeeIds, $base);
            $this->seedPayrollRuns($outlet->id, $employeeIds, $base);
        }
    }

    /** @param  list<int>  $employeeIds */
    private function seedAttendance(array $employeeIds, CarbonImmutable $base): void
    {
        $statuses = ['present', 'present', 'present', 'late', 'overtime', 'day_off', 'sick'];

        for ($day = 0; $day < 90; $day++) {
            $date = $base->subDays(90 - $day)->toDateString();
            foreach ($employeeIds as $empIndex => $employeeId) {
                $status = $statuses[($day + $empIndex) % count($statuses)];
                if ($status === 'day_off' && $day % 7 === 0) {
                    continue;
                }

                $checkIn = $status === 'sick' ? null : "{$date} 08:".str_pad((string) (($empIndex % 3) * 10), 2, '0', STR_PAD_LEFT).':00';
                $checkOut = $status === 'sick' ? null : ($status === 'overtime' ? "{$date} 20:00:00" : "{$date} 17:00:00");

                Attendance::query()->updateOrCreate(
                    ['employee_id' => $employeeId, 'attendance_date' => $date],
                    [
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'source' => 'manual',
                        'status' => $status === 'sick' ? 'absent' : ($status === 'late' ? 'late' : 'present'),
                        'sync_key' => "demo-{$employeeId}-{$date}",
                        'notes' => $status === 'sick' ? 'Medical leave' : ($status === 'overtime' ? 'Overtime shift' : 'Demo attendance'),
                    ],
                );
            }
        }
    }

    /** @param  list<int>  $employeeIds */
    private function seedPayrollRuns(int $outletId, array $employeeIds, CarbonImmutable $base): void
    {
        $manager = User::query()->whereHas('outlets', fn ($q) => $q->where('outlets.id', $outletId))->first();
        $states = [
            ['status' => PayrollRunV2::STATUS_PAID, 'payment' => PayrollRunV2::PAYMENT_PAID],
            ['status' => PayrollRunV2::STATUS_FINALIZED, 'payment' => PayrollRunV2::PAYMENT_PENDING],
            ['status' => PayrollRunV2::STATUS_DRAFT, 'payment' => PayrollRunV2::PAYMENT_PENDING],
        ];

        foreach ($states as $index => $state) {
            $periodStart = $base->subMonths(3 - $index)->startOfMonth();
            $periodEnd = $periodStart->endOfMonth();

            $preparation = PayrollPreparationPeriod::query()->updateOrCreate(
                ['outlet_id' => $outletId, 'period_start' => $periodStart->toDateString()],
                [
                    'period_end' => $periodEnd->toDateString(),
                    'status' => PayrollPreparationPeriod::STATUS_LOCKED,
                    'approved_by' => $manager?->id,
                    'approved_at' => $periodStart->addDays(20),
                    'locked_by' => $manager?->id,
                    'locked_at' => $periodStart->addDays(22),
                    'generated_at' => $periodStart->addDays(18),
                ],
            );

            $run = PayrollRunV2::query()->updateOrCreate(
                ['outlet_id' => $outletId, 'closed_notes' => "demo-period-{$index}"],
                [
                    'payroll_preparation_period_id' => $preparation->id,
                    'status' => $state['status'],
                    'payment_status' => $state['payment'],
                    'approved_by' => $state['status'] !== PayrollRunV2::STATUS_DRAFT ? $manager?->id : null,
                    'approved_at' => $state['status'] !== PayrollRunV2::STATUS_DRAFT ? $periodStart->addDays(25) : null,
                    'finalized_at' => in_array($state['status'], [PayrollRunV2::STATUS_FINALIZED, PayrollRunV2::STATUS_PAID], true)
                        ? $periodStart->addDays(27) : null,
                    'paid_at' => $state['status'] === PayrollRunV2::STATUS_PAID ? $periodStart->addDays(28) : null,
                ],
            );

            foreach (['calculated', 'approved', 'finalized', 'posting_created', 'payment_completed'] as $action) {
                PayrollRunAudit::query()->updateOrCreate(
                    ['payroll_run_id' => $run->id, 'action' => $action],
                    ['performed_by' => $manager?->id, 'notes' => "Demo {$action} for period {$periodStart->format('Y-m')}"],
                );
            }
        }

        unset($employeeIds);
    }
}
