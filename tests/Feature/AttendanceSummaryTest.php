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

class AttendanceSummaryTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_present_summary_when_on_time(): void
    {
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-10',
            'status' => 'published',
            'published_at' => now(),
        ]);

        AttendanceRecord::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'attendance_date' => '2026-07-10',
            'clock_in' => '2026-07-10 08:00:00',
            'clock_out' => '2026-07-10 16:00:00',
            'worked_minutes' => 480,
            'status' => 'present',
            'source' => 'csv_import',
        ]);

        app(AttendanceSummaryService::class)->upsertSummary((int) $employee->id, '2026-07-10');

        $summary = AttendanceDailySummary::query()->first();
        $this->assertNotNull($summary);
        $this->assertSame(AttendanceDailySummary::STATUS_PRESENT, $summary->attendance_status);
        $this->assertSame(0, (int) $summary->late_minutes);
        $this->assertSame(0, (int) $summary->early_leave_minutes);
        $this->assertFalse($summary->is_absent);
    }

    public function test_late_summary_stores_late_minutes(): void
    {
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-11',
            'status' => 'published',
            'published_at' => now(),
        ]);

        AttendanceRecord::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-11',
            'clock_in' => '2026-07-11 08:10:00',
            'clock_out' => '2026-07-11 16:00:00',
            'status' => 'late',
            'source' => 'csv_import',
        ]);

        app(AttendanceSummaryService::class)->upsertSummary((int) $employee->id, '2026-07-11');

        $summary = AttendanceDailySummary::query()->first();
        $this->assertSame(AttendanceDailySummary::STATUS_LATE, $summary->attendance_status);
        $this->assertGreaterThan(0, (int) $summary->late_minutes);
    }

    public function test_early_leave_summary_stores_minutes(): void
    {
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-12',
            'status' => 'published',
            'published_at' => now(),
        ]);

        AttendanceRecord::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-12',
            'clock_in' => '2026-07-12 08:00:00',
            'clock_out' => '2026-07-12 15:30:00',
            'status' => 'early_leave',
            'source' => 'csv_import',
        ]);

        app(AttendanceSummaryService::class)->upsertSummary((int) $employee->id, '2026-07-12');

        $summary = AttendanceDailySummary::query()->first();
        $this->assertSame(AttendanceDailySummary::STATUS_EARLY_LEAVE, $summary->attendance_status);
        $this->assertGreaterThan(0, (int) $summary->early_leave_minutes);
    }

    public function test_incomplete_summary(): void
    {
        [$employee, $shift, $outlet] = $this->seedFixtures();

        AttendanceRecord::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-13',
            'clock_in' => '2026-07-13 08:00:00',
            'clock_out' => null,
            'status' => 'incomplete',
            'source' => 'csv_import',
        ]);

        app(AttendanceSummaryService::class)->upsertSummary((int) $employee->id, '2026-07-13');

        $summary = AttendanceDailySummary::query()->first();
        $this->assertTrue($summary->is_incomplete);
        $this->assertTrue($summary->requires_review);
        $this->assertSame(AttendanceDailySummary::STATUS_REVIEW_REQUIRED, $summary->attendance_status);
    }

    public function test_scheduler_command_is_idempotent(): void
    {
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-14',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->artisan('attendance:generate-summaries', ['--date' => '2026-07-14'])
            ->assertSuccessful();

        $this->assertSame(1, AttendanceDailySummary::query()->count());

        $this->artisan('attendance:generate-summaries', ['--date' => '2026-07-14'])
            ->assertSuccessful();

        $this->assertSame(1, AttendanceDailySummary::query()->count());
    }

    public function test_summaries_api_lists_rows(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-15',
            'status' => 'published',
            'published_at' => now(),
        ]);

        app(AttendanceSummaryService::class)->generateForDate('2026-07-15', (int) $outlet->id);

        $this->getJson('/api/v1/attendance/summaries?outletId='.$outlet->id.'&fromDate=2026-07-15&toDate=2026-07-15')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attendanceStatus', 'absent');
    }

    /**
     * @return array{0: Employee, 1: Shift, 2: Outlet}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Summary Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'sum-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-SUM-01',
            'full_name' => 'Summary Worker',
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
