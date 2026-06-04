<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\HR\Services\AttendanceSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class AttendanceAbsenceDetectionTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_absent_when_published_schedule_without_attendance(): void
    {
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-20',
            'status' => 'published',
            'published_at' => now(),
        ]);

        app(AttendanceSummaryService::class)->upsertSummary((int) $employee->id, '2026-07-20');

        $summary = AttendanceDailySummary::query()->first();
        $this->assertNotNull($summary);
        $this->assertTrue($summary->is_absent);
        $this->assertSame(AttendanceDailySummary::STATUS_ABSENT, $summary->attendance_status);
        $this->assertNull($summary->clock_in);
        $this->assertNull($summary->clock_out);
    }

    public function test_no_absent_for_draft_roster_only(): void
    {
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-21',
            'status' => 'draft',
        ]);

        $result = app(AttendanceSummaryService::class)->generateForDate('2026-07-21');

        $this->assertSame(0, $result['created'] + $result['updated']);
        $this->assertSame(0, AttendanceDailySummary::query()->count());
    }

    public function test_no_absent_for_published_off_day_without_shift(): void
    {
        [$employee, , $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => null,
            'roster_date' => '2026-07-22',
            'status' => 'published',
            'published_at' => now(),
        ]);

        app(AttendanceSummaryService::class)->generateForDate('2026-07-22');

        $this->assertSame(0, AttendanceDailySummary::query()->count());
    }

    /**
     * @return array{0: Employee, 1: Shift, 2: Outlet}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Absent Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'abs-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-ABS-01',
            'full_name' => 'Absent Worker',
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
