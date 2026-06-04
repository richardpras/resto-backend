<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\HR\Services\AttendanceMatchingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_present_on_time(): void
    {
        $service = app(AttendanceMatchingService::class);
        $shift = $this->makeShift();

        $result = $service->calculateStatusAndWorkedMinutes(
            Carbon::parse('2026-07-01 08:03:00'),
            Carbon::parse('2026-07-01 16:00:00'),
            $shift,
            '2026-07-01',
        );

        $this->assertSame(AttendanceRecord::STATUS_PRESENT, $result['status']);
        $this->assertSame(477, $result['worked_minutes']);
    }

    public function test_status_late_with_grace(): void
    {
        $service = app(AttendanceMatchingService::class);
        $shift = $this->makeShift();

        $result = $service->calculateStatusAndWorkedMinutes(
            Carbon::parse('2026-07-01 08:07:00'),
            Carbon::parse('2026-07-01 16:00:00'),
            $shift,
            '2026-07-01',
        );

        $this->assertSame(AttendanceRecord::STATUS_LATE, $result['status']);
    }

    public function test_status_early_leave(): void
    {
        $service = app(AttendanceMatchingService::class);
        $shift = $this->makeShift();

        $result = $service->calculateStatusAndWorkedMinutes(
            Carbon::parse('2026-07-01 08:00:00'),
            Carbon::parse('2026-07-01 15:30:00'),
            $shift,
            '2026-07-01',
        );

        $this->assertSame(AttendanceRecord::STATUS_EARLY_LEAVE, $result['status']);
    }

    public function test_status_incomplete_single_punch(): void
    {
        $service = app(AttendanceMatchingService::class);

        $result = $service->calculateStatusAndWorkedMinutes(
            Carbon::parse('2026-07-01 08:00:00'),
            null,
            $this->makeShift(),
            '2026-07-01',
        );

        $this->assertSame(AttendanceRecord::STATUS_INCOMPLETE, $result['status']);
        $this->assertNull($result['worked_minutes']);
    }

    public function test_roster_matching_links_shift(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'Match Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'match',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-M',
            'full_name' => 'Matcher',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $shift = Shift::query()->create([
            'tenant_id' => 1,
            'code' => 'EVE',
            'name' => 'Evening',
            'start_time' => '16:00:00',
            'end_time' => '23:00:00',
            'late_tolerance_minutes' => 5,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-10',
            'status' => 'draft',
        ]);

        $match = app(AttendanceMatchingService::class)->resolveRosterAndShift((int) $employee->id, '2026-07-10');

        $this->assertNotNull($match['roster']);
        $this->assertSame((int) $shift->id, (int) $match['shift']?->id);
    }

    private function makeShift(): Shift
    {
        return new Shift([
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);
    }
}
