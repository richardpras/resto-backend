<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\HR\Services\AttendanceSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class AttendancePayrollPreparationTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_payroll_preparation_aggregates_period_metrics(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet] = $this->seedFixtures();

        foreach (['2026-07-01', '2026-07-02', '2026-07-03'] as $date) {
            EmployeeRoster::query()->create([
                'outlet_id' => $outlet->id,
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'roster_date' => $date,
                'status' => 'published',
                'published_at' => now(),
            ]);
        }

        AttendanceRecord::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-01',
            'clock_in' => '2026-07-01 08:00:00',
            'clock_out' => '2026-07-01 16:00:00',
            'worked_minutes' => 480,
            'status' => 'present',
            'source' => 'csv_import',
        ]);

        AttendanceRecord::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-02',
            'clock_in' => '2026-07-02 08:15:00',
            'clock_out' => '2026-07-02 16:00:00',
            'status' => 'late',
            'source' => 'csv_import',
        ]);

        app(AttendanceSummaryService::class)->generateForDate('2026-07-01');
        app(AttendanceSummaryService::class)->generateForDate('2026-07-02');
        app(AttendanceSummaryService::class)->generateForDate('2026-07-03');

        $this->getJson('/api/v1/attendance/payroll-preparation?'.http_build_query([
            'outletId' => $outlet->id,
            'periodStart' => '2026-07-01',
            'periodEnd' => '2026-07-03',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employeeId', $employee->id)
            ->assertJsonPath('data.0.attendanceDays', 2)
            ->assertJsonPath('data.0.absentDays', 1)
            ->assertJsonPath('data.0.lateCount', 1)
            ->assertJsonPath('data.0.overtimeMinutes', 0)
            ->assertJsonPath('data.0.overtimeHours', 0);
    }

    public function test_payroll_preparation_does_not_modify_payroll_tables(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, , $outlet] = $this->seedFixtures();

        $payrollCountBefore = \Illuminate\Support\Facades\DB::table('payrolls')->count();

        $this->getJson('/api/v1/attendance/payroll-preparation?periodStart=2026-07-01&periodEnd=2026-07-31')
            ->assertOk();

        $this->assertSame($payrollCountBefore, \Illuminate\Support\Facades\DB::table('payrolls')->count());
    }

    /**
     * @return array{0: Employee, 1: Shift, 2: Outlet}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Prep Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'prep-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-PREP-01',
            'full_name' => 'Prep Worker',
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

        return [$employee, $shift, $outlet];
    }
}
