<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollPreparationWorkflowTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_approve_lock_and_regenerate_restrictions(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $outlet] = $this->seedFixtures();

        AttendanceDailySummary::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-11-01',
            'clock_in' => '2026-11-01 08:00:00',
            'clock_out' => '2026-11-01 16:00:00',
            'is_absent' => false,
            'requires_review' => false,
            'attendance_status' => 'present',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $periodRes = $this->postJson('/api/v1/payroll-preparation-periods', [
            'outletId' => $outlet->id,
            'periodStart' => '2026-11-01',
            'periodEnd' => '2026-11-07',
        ])->assertCreated();

        $periodId = (int) $periodRes->json('data.id');

        $this->patchJson('/api/v1/payroll-preparation-periods/'.$periodId.'/approve')->assertStatus(422);

        $this->postJson('/api/v1/payroll-preparation-periods/'.$periodId.'/generate')->assertOk();

        $this->patchJson('/api/v1/payroll-preparation-periods/'.$periodId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->postJson('/api/v1/payroll-preparation-periods/'.$periodId.'/generate')->assertOk();

        $countAfterRegen = PayrollPreparationSnapshot::query()
            ->where('preparation_period_id', $periodId)
            ->count();

        $this->assertSame(1, $countAfterRegen);

        $this->patchJson('/api/v1/payroll-preparation-periods/'.$periodId.'/lock')
            ->assertOk()
            ->assertJsonPath('data.status', 'locked');

        $this->postJson('/api/v1/payroll-preparation-periods/'.$periodId.'/generate')->assertStatus(422);

        $this->patchJson('/api/v1/payroll-preparation-periods/'.$periodId.'/approve')->assertStatus(422);

        $period = PayrollPreparationPeriod::query()->find($periodId);
        $this->assertSame(PayrollPreparationPeriod::STATUS_LOCKED, $period->status);
    }

    /**
     * @return array{0: Employee, 1: Outlet}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Flow Prep',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'flow-prep',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-PREP-FLOW',
            'full_name' => 'Flow Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        return [$employee, $outlet];
    }
}
