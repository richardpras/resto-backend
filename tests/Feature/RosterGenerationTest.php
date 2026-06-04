<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\EmployeeShiftAssignment;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class RosterGenerationTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_generate_roster_from_shift_assignment(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift] = $this->seedFixtures();

        EmployeeShiftAssignment::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/rosters/generate', [
            'employeeId' => $employee->id,
            'fromDate' => '2026-07-01',
            'toDate' => '2026-07-05',
        ])->assertOk()
            ->assertJsonPath('data.created', 5);

        $this->assertSame(5, EmployeeRoster::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(
            (int) $shift->id,
            (int) EmployeeRoster::query()->where('employee_id', $employee->id)->value('shift_id'),
        );
    }

    public function test_generate_skips_existing_unless_overwrite(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift] = $this->seedFixtures();

        EmployeeShiftAssignment::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        EmployeeRoster::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'shift_id' => null,
            'roster_date' => '2026-07-03',
            'status' => EmployeeRoster::STATUS_DRAFT,
        ]);

        $this->postJson('/api/v1/rosters/generate', [
            'employeeId' => $employee->id,
            'fromDate' => '2026-07-01',
            'toDate' => '2026-07-03',
        ])->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 1);

        $evening = Shift::query()->create([
            'tenant_id' => 1,
            'code' => 'EVE',
            'name' => 'Evening',
            'start_time' => '16:00:00',
            'end_time' => '23:00:00',
            'late_tolerance_minutes' => 5,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        EmployeeShiftAssignment::query()
            ->where('employee_id', $employee->id)
            ->update(['shift_id' => $evening->id]);

        $this->postJson('/api/v1/rosters/generate', [
            'employeeId' => $employee->id,
            'fromDate' => '2026-07-01',
            'toDate' => '2026-07-03',
            'overwriteExisting' => true,
        ])->assertOk()
            ->assertJsonPath('data.updated', 3);

        $this->assertSame(
            (int) $evening->id,
            (int) EmployeeRoster::query()
                ->where('employee_id', $employee->id)
                ->where('roster_date', '2026-07-03')
                ->value('shift_id'),
        );
    }

    /**
     * @return array{0: Employee, 1: Shift}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Gen Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'gen-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-GEN',
            'full_name' => 'Generator Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $shift = Shift::query()->create([
            'tenant_id' => 1,
            'code' => 'GEN-SHIFT',
            'name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'late_tolerance_minutes' => 10,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        return [$employee, $shift];
    }
}
