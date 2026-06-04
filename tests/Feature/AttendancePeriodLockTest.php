<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class AttendancePeriodLockTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_approve_and_lock_period(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet] = $this->seedFixtures();

        $this->postJson('/api/v1/attendance/periods', [
            'outletId' => $outlet->id,
            'periodStart' => '2026-08-01',
            'periodEnd' => '2026-08-07',
            'notes' => 'August week 1',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $period = AttendancePeriodLock::query()->first();
        $this->assertNotNull($period);

        $this->patchJson('/api/v1/attendance/periods/'.$period->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->patchJson('/api/v1/attendance/periods/'.$period->id.'/lock')
            ->assertOk()
            ->assertJsonPath('data.status', 'locked');

        $period->refresh();
        $this->assertNotNull($period->approved_at);
        $this->assertNotNull($period->locked_at);
    }

    public function test_duplicate_period_rejected(): void
    {
        $this->actingAsHrmApiAdministrator();
        [, , $outlet] = $this->seedFixtures();

        $payload = [
            'outletId' => $outlet->id,
            'periodStart' => '2026-08-10',
            'periodEnd' => '2026-08-16',
        ];

        $this->postJson('/api/v1/attendance/periods', $payload)->assertCreated();
        $this->postJson('/api/v1/attendance/periods', $payload)->assertStatus(422);
    }

    public function test_review_blocked_after_lock(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-08-05',
            'status' => 'published',
            'published_at' => now(),
        ]);

        AttendanceRecord::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-05',
            'clock_in' => '2026-08-05 08:00:00',
            'status' => 'incomplete',
            'source' => 'csv_import',
        ]);

        $summary = AttendanceDailySummary::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-05',
            'attendance_status' => 'incomplete',
            'is_incomplete' => true,
            'requires_review' => true,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'is_absent' => false,
        ]);

        $period = AttendancePeriodLock::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-07',
            'status' => AttendancePeriodLock::STATUS_LOCKED,
            'locked_at' => now(),
        ]);

        $this->postJson('/api/v1/attendance/summaries/'.$summary->id.'/review', [
            'reviewType' => 'approved',
            'notes' => 'Should fail',
        ])->assertForbidden();

        $this->assertSame(0, $summary->reviews()->count());
        $this->assertNotNull($period);
    }

    public function test_correction_blocked_after_lock(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, , $outlet] = $this->seedFixtures();

        $record = AttendanceRecord::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-06',
            'clock_in' => '2026-08-06 08:00:00',
            'clock_out' => '2026-08-06 16:00:00',
            'status' => 'present',
            'source' => 'csv_import',
        ]);

        AttendancePeriodLock::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-07',
            'status' => AttendancePeriodLock::STATUS_LOCKED,
            'locked_at' => now(),
        ]);

        $this->patchJson('/api/v1/attendance/'.$record->id, [
            'clockIn' => '09:00',
        ])->assertForbidden();
    }

    public function test_reopen_only_from_approved_and_not_when_locked(): void
    {
        $this->actingAsHrmApiAdministrator();
        [, , $outlet] = $this->seedFixtures();

        $draft = AttendancePeriodLock::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-07',
            'status' => AttendancePeriodLock::STATUS_DRAFT,
        ]);

        $this->patchJson('/api/v1/attendance/periods/'.$draft->id.'/reopen')->assertStatus(422);

        $approved = AttendancePeriodLock::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-09-08',
            'period_end' => '2026-09-14',
            'status' => AttendancePeriodLock::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $this->patchJson('/api/v1/attendance/periods/'.$approved->id.'/reopen')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $locked = AttendancePeriodLock::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-09-15',
            'period_end' => '2026-09-21',
            'status' => AttendancePeriodLock::STATUS_LOCKED,
            'locked_at' => now(),
        ]);

        $this->patchJson('/api/v1/attendance/periods/'.$locked->id.'/reopen')->assertForbidden();
    }

    /**
     * @return array{0: Employee, 1: Shift, 2: Outlet}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Period Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'per-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-PER-01',
            'full_name' => 'Period Worker',
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
