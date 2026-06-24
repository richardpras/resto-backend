<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollPreparationPeriodMasterTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_master_also_creates_linked_attendance_period(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();
        $this->grantHrmApiUserOutletAccess((int) $outlet->id);

        $this->postJson('/api/v1/payroll-preparation-periods', [
            'outletId' => $outlet->id,
            'periodStart' => '2026-05-01',
            'periodEnd' => '2026-05-31',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.attendancePeriodStatus', 'draft');

        $prep = PayrollPreparationPeriod::query()->first();
        $attendance = AttendancePeriodLock::query()->first();

        $this->assertNotNull($prep);
        $this->assertNotNull($attendance);
        $this->assertSame((int) $prep->id, (int) $attendance->payroll_preparation_period_id);
        $this->assertSame('2026-05-01', $prep->period_start->toDateString());
        $this->assertSame('2026-05-31', $prep->period_end->toDateString());
    }

    public function test_overlap_rejected_when_attendance_period_exists(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();
        $this->grantHrmApiUserOutletAccess((int) $outlet->id);

        AttendancePeriodLock::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => AttendancePeriodLock::STATUS_DRAFT,
        ]);

        $this->postJson('/api/v1/payroll-preparation-periods', [
            'outletId' => $outlet->id,
            'periodStart' => '2026-05-01',
            'periodEnd' => '2026-06-24',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['periodStart']);
    }

    public function test_generate_rejected_until_attendance_approved(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $outlet] = $this->seedEmployeeAndOutlet();

        $periodId = $this->createMasterPeriod($outlet);

        $this->postJson('/api/v1/payroll-preparation-periods/'.$periodId.'/generate')
            ->assertStatus(422);

        $attendance = AttendancePeriodLock::query()->where('payroll_preparation_period_id', $periodId)->first();
        $this->patchJson('/api/v1/attendance/periods/'.$attendance->id.'/approve')->assertOk();

        $this->postJson('/api/v1/payroll-preparation-periods/'.$periodId.'/generate')->assertOk();
        $this->assertNotNull(PayrollPreparationPeriod::query()->find($periodId)?->generated_at);
    }

    public function test_lock_prep_syncs_attendance_lock(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $outlet] = $this->seedEmployeeAndOutlet();

        AttendanceDailySummary::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-06-01',
            'clock_in' => '2026-06-01 08:00:00',
            'clock_out' => '2026-06-01 16:00:00',
            'is_absent' => false,
            'requires_review' => false,
            'attendance_status' => 'present',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $periodId = $this->createMasterPeriod($outlet, '2026-06-01', '2026-06-30');
        $attendance = AttendancePeriodLock::query()->where('payroll_preparation_period_id', $periodId)->first();

        $this->patchJson('/api/v1/attendance/periods/'.$attendance->id.'/approve')->assertOk();
        $this->postJson('/api/v1/payroll-preparation-periods/'.$periodId.'/generate')->assertOk();
        $this->patchJson('/api/v1/payroll-preparation-periods/'.$periodId.'/approve')->assertOk();
        $this->patchJson('/api/v1/payroll-preparation-periods/'.$periodId.'/lock')->assertOk();

        $attendance->refresh();
        $this->assertSame(AttendancePeriodLock::STATUS_LOCKED, $attendance->status);
    }

    public function test_delete_draft_master_cascades_attendance_period(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();
        $this->grantHrmApiUserOutletAccess((int) $outlet->id);

        $periodId = $this->createMasterPeriod($outlet);

        $this->deleteJson('/api/v1/payroll-preparation-periods/'.$periodId)->assertOk();

        $this->assertDatabaseMissing('payroll_preparation_periods', ['id' => $periodId]);
        $this->assertSame(0, AttendancePeriodLock::query()->count());
    }

    public function test_delete_linked_attendance_period_rejected(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();
        $this->grantHrmApiUserOutletAccess((int) $outlet->id);

        $periodId = $this->createMasterPeriod($outlet);
        $attendance = AttendancePeriodLock::query()->where('payroll_preparation_period_id', $periodId)->first();

        $this->deleteJson('/api/v1/attendance/periods/'.$attendance->id)->assertStatus(422);
    }

    public function test_reopen_attendance_demotes_prep_to_draft(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $outlet] = $this->seedEmployeeAndOutlet();

        $periodId = $this->createMasterPeriod($outlet, '2026-07-01', '2026-07-31');
        $attendance = AttendancePeriodLock::query()->where('payroll_preparation_period_id', $periodId)->first();

        $this->patchJson('/api/v1/attendance/periods/'.$attendance->id.'/approve')->assertOk();
        $this->postJson('/api/v1/payroll-preparation-periods/'.$periodId.'/generate')->assertOk();
        $this->patchJson('/api/v1/payroll-preparation-periods/'.$periodId.'/approve')->assertOk();

        $this->patchJson('/api/v1/attendance/periods/'.$attendance->id.'/reopen')->assertOk();

        $prep = PayrollPreparationPeriod::query()->find($periodId);
        $attendance->refresh();

        $this->assertSame(PayrollPreparationPeriod::STATUS_DRAFT, $prep->status);
        $this->assertNull($prep->generated_at);
        $this->assertSame(AttendancePeriodLock::STATUS_DRAFT, $attendance->status);
    }

    public function test_post_attendance_period_create_blocked(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();
        $this->grantHrmApiUserOutletAccess((int) $outlet->id);

        $this->postJson('/api/v1/attendance/periods', [
            'outletId' => $outlet->id,
            'periodStart' => '2026-08-01',
            'periodEnd' => '2026-08-31',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['periodStart']);
    }

    private function createMasterPeriod(
        Outlet $outlet,
        string $periodStart = '2026-05-01',
        string $periodEnd = '2026-05-31',
    ): int {
        $response = $this->postJson('/api/v1/payroll-preparation-periods', [
            'outletId' => $outlet->id,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
        ])->assertCreated();

        return (int) $response->json('data.id');
    }

    private function seedOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Master Prep Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'master-prep',
        ]);
    }

    /**
     * @return array{0: Employee, 1: Outlet}
     */
    private function seedEmployeeAndOutlet(): array
    {
        $outlet = $this->seedOutlet();
        $this->grantHrmApiUserOutletAccess((int) $outlet->id);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-MASTER-01',
            'full_name' => 'Master Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        return [$employee, $outlet];
    }
}
