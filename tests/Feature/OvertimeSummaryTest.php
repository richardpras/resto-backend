<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\OvertimeDailySummary;
use App\Models\Modules\HR\Domain\OvertimeType;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class OvertimeSummaryTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_summary_generated_on_approve(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        $res = $this->postJson('/api/v1/overtime-requests', [
            'employeeId' => $employee->id,
            'overtimeTypeId' => $type->id,
            'overtimeDate' => '2026-11-10',
            'startTime' => '18:00',
            'endTime' => '20:00',
        ])->assertCreated();

        $this->patchJson('/api/v1/overtime-requests/'.$res->json('data.id').'/approve')->assertOk();

        $summary = OvertimeDailySummary::query()->first();
        $this->assertNotNull($summary);
        $this->assertSame(120, (int) $summary->approved_minutes);
        $this->assertEquals(2.0, (float) $summary->approved_hours);
        $this->assertSame(1, (int) $summary->request_count);

        $this->getJson('/api/v1/overtime-summaries?employeeId='.$employee->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_payroll_preparation_includes_overtime_totals(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type, $outlet] = $this->seedFixturesWithOutlet();

        $res = $this->postJson('/api/v1/overtime-requests', [
            'employeeId' => $employee->id,
            'overtimeTypeId' => $type->id,
            'overtimeDate' => '2026-11-12',
            'startTime' => '17:00',
            'endTime' => '19:00',
        ])->assertCreated();

        $this->patchJson('/api/v1/overtime-requests/'.$res->json('data.id').'/approve')->assertOk();

        \App\Models\Modules\HR\Domain\AttendanceDailySummary::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-11-12',
            'attendance_status' => 'present',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'is_absent' => false,
            'is_incomplete' => false,
            'requires_review' => false,
        ]);

        $this->getJson('/api/v1/attendance/payroll-preparation?'.http_build_query([
            'outletId' => $outlet->id,
            'periodStart' => '2026-11-01',
            'periodEnd' => '2026-11-30',
        ]))
            ->assertOk()
            ->assertJsonPath('data.0.overtimeMinutes', 120)
            ->assertJsonPath('data.0.overtimeHours', 2);
    }

    /**
     * @return array{0: Employee, 1: OvertimeType}
     */
    private function seedFixtures(): array
    {
        [$employee, $type] = $this->seedFixturesWithOutlet();

        return [$employee, $type];
    }

    /**
     * @return array{0: Employee, 1: OvertimeType, 2: Outlet}
     */
    private function seedFixturesWithOutlet(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Sum OT',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ot-sum',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-OT-SUM',
            'full_name' => 'Sum Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $type = OvertimeType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'regular',
            'name' => 'Regular',
            'multiplier' => 1.5,
            'is_active' => true,
        ]);

        return [$employee, $type, $outlet];
    }
}
