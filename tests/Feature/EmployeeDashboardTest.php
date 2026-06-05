<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\EmployeeLeaveBalance;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\LeaveType;
use App\Models\Modules\HR\Domain\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\EssPortalFixture;
use Tests\TestCase;

class EmployeeDashboardTest extends TestCase
{
    use EssPortalFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->setupEssPassport();
        $this->enableEssPortal();
    }

    public function test_dashboard_widgets_returned(): void
    {
        [$employee, $user] = $this->seedEmployeePortalUser();

        $shift = Shift::query()->create([
            'code' => 'MORN',
            'name' => 'Morning',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'active' => true,
        ]);

        EmployeeRoster::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => now()->toDateString(),
            'status' => EmployeeRoster::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $leaveType = LeaveType::query()->create([
            'outlet_id' => $employee->outlet_id,
            'name' => 'Annual Leave',
            'code' => 'AL',
            'deduct_leave_balance' => true,
            'is_active' => true,
        ]);

        EmployeeLeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'allocated_days' => 12,
            'used_days' => 2,
            'remaining_days' => 10,
        ]);

        Passport::actingAs($user, [], 'employee_api');

        $res = $this->getJson('/api/v1/ess/dashboard')->assertOk();

        $this->assertNotNull($res->json('data.employee'));
        $this->assertNotNull($res->json('data.todaySchedule.shift.name'));
        $this->assertSame('Morning', $res->json('data.todaySchedule.shift.name'));
        $this->assertArrayHasKey('presentDays', $res->json('data.attendanceSummary'));
        $this->assertCount(1, $res->json('data.leaveBalanceSummary'));
        $this->assertIsArray($res->json('data.upcomingShifts'));
        $this->assertIsArray($res->json('data.notifications'));
    }
}
