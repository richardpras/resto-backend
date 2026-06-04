<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\AttendanceReview;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\HR\Services\AttendanceSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class AttendanceReviewTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_review_creates_history_and_clears_requires_review(): void
    {
        $user = $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-25',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $summary = app(AttendanceSummaryService::class)->upsertSummary((int) $employee->id, '2026-07-25');
        $summary->update(['requires_review' => true, 'attendance_status' => AttendanceDailySummary::STATUS_REVIEW_REQUIRED]);

        $this->postJson('/api/v1/attendance/summaries/'.$summary->id.'/review', [
            'reviewType' => 'approved',
            'notes' => 'Verified by HR',
        ])->assertOk()
            ->assertJsonPath('data.requiresReview', false);

        $summary->refresh();
        $this->assertFalse($summary->requires_review);
        $this->assertNotNull($summary->reviewed_at);

        $review = AttendanceReview::query()->first();
        $this->assertNotNull($review);
        $this->assertSame('approved', $review->review_type);
        $this->assertSame((int) $user->id, (int) $review->reviewer_id);
    }

    public function test_excused_absence_clears_absent_flag(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-26',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $summary = app(AttendanceSummaryService::class)->upsertSummary((int) $employee->id, '2026-07-26');
        $this->assertTrue($summary->is_absent);

        $this->postJson('/api/v1/attendance/summaries/'.$summary->id.'/review', [
            'reviewType' => 'excused_absence',
            'notes' => 'Medical leave',
        ])->assertOk();

        $summary->refresh();
        $this->assertFalse($summary->is_absent);
    }

    /**
     * @return array{0: Employee, 1: Shift, 2: Outlet}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Review Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'rev-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-REV-01',
            'full_name' => 'Review Worker',
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
