<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\EmployeeShiftAssignment;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class AttendanceImportTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_import_csv_groups_punches_and_creates_record(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeShiftAssignment::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-01',
            'status' => 'published',
        ]);

        $csv = <<<'CSV'
employee_code,timestamp
EMP-ATT-01,2026-07-01 08:02:11
EMP-ATT-01,2026-07-01 16:01:22
CSV;

        $this->postJson('/api/v1/attendance/import', [
            'outletId' => $outlet->id,
            'csv' => $csv,
            'filename' => 'july-punches.csv',
        ])->assertCreated()
            ->assertJsonPath('data.created', 1);

        $record = AttendanceRecord::query()->where('employee_id', $employee->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('08:02', $record->clock_in->format('H:i'));
        $this->assertSame('16:01', $record->clock_out->format('H:i'));
        $this->assertSame((int) $shift->id, (int) $record->shift_id);
        $this->assertSame(AttendanceRecord::SOURCE_CSV_IMPORT, $record->source);
    }

    public function test_import_preview_does_not_persist(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, , $outlet] = $this->seedFixtures();

        $csv = "employee_code,timestamp\n{$employee->employee_no},2026-07-02 09:00:00\n";

        $this->postJson('/api/v1/attendance/import', [
            'outletId' => $outlet->id,
            'csv' => $csv,
            'preview' => true,
        ])->assertOk()
            ->assertJsonCount(1, 'data.preview');

        $this->assertSame(0, AttendanceRecord::query()->count());
    }

    public function test_duplicate_import_skips_without_overwrite(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet] = $this->seedFixtures();

        EmployeeRoster::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-03',
            'status' => 'draft',
        ]);

        $csv = "employee_code,timestamp\n{$employee->employee_no},2026-07-03 08:00:00\n{$employee->employee_no},2026-07-03 17:00:00\n";

        $this->postJson('/api/v1/attendance/import', [
            'outletId' => $outlet->id,
            'csv' => $csv,
        ])->assertCreated();

        $this->postJson('/api/v1/attendance/import', [
            'outletId' => $outlet->id,
            'csv' => $csv,
        ])->assertCreated()
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.created', 0);

        $this->assertSame(1, AttendanceRecord::query()->count());
    }

    /**
     * @return array{0: Employee, 1: Shift, 2: Outlet}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Att Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'att-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-ATT-01',
            'full_name' => 'Attendance Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $shift = Shift::query()->create([
            'tenant_id' => 1,
            'code' => 'MORN',
            'name' => 'Morning',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'late_tolerance_minutes' => 5,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        return [$employee, $shift, $outlet];
    }
}
