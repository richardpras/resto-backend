<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\OvertimeDailySummary;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollPreparationSummaryTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_summary_aggregates_snapshot_totals(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $outlet] = $this->seedFixtures();
        $this->grantHrmApiUserOutletAccess((int) $outlet->id);

        $periodRes = $this->postJson('/api/v1/payroll-preparation-periods', [
            'outletId' => $outlet->id,
            'periodStart' => '2026-12-01',
            'periodEnd' => '2026-12-31',
        ])->assertCreated();

        $periodId = (int) $periodRes->json('data.id');

        OvertimeDailySummary::query()->create([
            'employee_id' => $employee->id,
            'overtime_date' => '2026-12-10',
            'approved_minutes' => 90,
            'approved_hours' => 1.5,
            'request_count' => 1,
        ]);

        $attendance = AttendancePeriodLock::query()->where('payroll_preparation_period_id', $periodId)->first();
        $this->patchJson('/api/v1/attendance/periods/'.$attendance->id.'/approve')->assertOk();

        $this->postJson('/api/v1/payroll-preparation-periods/'.$periodId.'/generate')->assertOk();

        $this->getJson('/api/v1/payroll-preparation-periods/'.$periodId.'/summary')
            ->assertOk()
            ->assertJsonPath('data.employeeCount', 1)
            ->assertJsonPath('data.overtimeHours', 1.5);

        $payrollCountBefore = \Illuminate\Support\Facades\DB::table('payroll_runs_v2')->count();
        $this->assertSame(0, $payrollCountBefore);
    }

    public function test_generate_rejected_while_attendance_period_still_draft(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $outlet] = $this->seedFixtures();
        $this->grantHrmApiUserOutletAccess((int) $outlet->id);

        AttendanceDailySummary::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-12-02',
            'clock_in' => '2026-12-02 08:00:00',
            'clock_out' => '2026-12-02 16:00:00',
            'is_absent' => false,
            'requires_review' => false,
            'attendance_status' => 'present',
            'late_minutes' => 10,
            'early_leave_minutes' => 0,
        ]);

        $periodRes = $this->postJson('/api/v1/payroll-preparation-periods', [
            'outletId' => $outlet->id,
            'periodStart' => '2026-12-01',
            'periodEnd' => '2026-12-07',
        ])->assertCreated();

        $periodId = (int) $periodRes->json('data.id');
        $this->postJson('/api/v1/payroll-preparation-periods/'.$periodId.'/generate')->assertStatus(422);

        $this->assertSame(0, PayrollPreparationSnapshot::query()->where('preparation_period_id', $periodId)->count());
    }

    /**
     * @return array{0: Employee, 1: Outlet}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Sum Prep',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'sum-prep',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-PREP-SUM',
            'full_name' => 'Sum Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        return [$employee, $outlet];
    }
}
