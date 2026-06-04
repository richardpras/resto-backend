<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class AttendancePayrollPreparationLockTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_payroll_preparation_includes_lock_status_meta(): void
    {
        $this->actingAsHrmApiAdministrator();
        [, $outlet] = $this->seedOutlet();

        AttendancePeriodLock::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-07',
            'status' => AttendancePeriodLock::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $this->getJson('/api/v1/attendance/payroll-preparation?'.http_build_query([
            'outletId' => $outlet->id,
            'periodStart' => '2026-08-01',
            'periodEnd' => '2026-08-07',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.lockStatus', 'approved')
            ->assertJsonPath('meta.approvedAt', fn ($v) => $v !== null)
            ->assertJsonPath('meta.lockedAt', null);
    }

    public function test_locked_period_shows_locked_at_in_meta(): void
    {
        $this->actingAsHrmApiAdministrator();
        [, $outlet] = $this->seedOutlet();

        AttendancePeriodLock::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-08-08',
            'period_end' => '2026-08-14',
            'status' => AttendancePeriodLock::STATUS_LOCKED,
            'approved_at' => now()->subDay(),
            'locked_at' => now(),
        ]);

        $this->getJson('/api/v1/attendance/payroll-preparation?'.http_build_query([
            'outletId' => $outlet->id,
            'periodStart' => '2026-08-08',
            'periodEnd' => '2026-08-14',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.lockStatus', 'locked')
            ->assertJsonPath('meta.lockedAt', fn ($v) => $v !== null);
    }

    /**
     * @return array{0: Employee, 1: Outlet}
     */
    private function seedOutlet(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Prep Lock Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pl-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-PL-01',
            'full_name' => 'Prep Lock Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        return [$employee, $outlet];
    }
}
