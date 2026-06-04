<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AttendanceCorrectionTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_manual_correction_updates_clocks_and_recalculates_status(): void
    {
        $admin = $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet] = $this->seedFixtures();

        $record = AttendanceRecord::query()->create([
            'outlet_id' => $outlet->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'attendance_date' => '2026-07-05',
            'clock_in' => '2026-07-05 08:20:00',
            'clock_out' => '2026-07-05 16:00:00',
            'worked_minutes' => 460,
            'status' => AttendanceRecord::STATUS_LATE,
            'source' => AttendanceRecord::SOURCE_CSV_IMPORT,
        ]);

        $this->patchJson('/api/v1/attendance/'.$record->id, [
            'clockIn' => '08:03',
            'clockOut' => '16:00',
            'notes' => 'Corrected after review',
        ])->assertOk()
            ->assertJsonPath('data.status', AttendanceRecord::STATUS_PRESENT)
            ->assertJsonPath('data.notes', 'Corrected after review')
            ->assertJsonPath('data.source', AttendanceRecord::SOURCE_CSV_IMPORT);

        $record->refresh();
        $this->assertSame($admin->id, (int) $record->updated_by);
    }

    public function test_attendance_routes_require_permission(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson('/api/v1/attendance')->assertForbidden();
        $this->postJson('/api/v1/attendance/import', [])->assertForbidden();
    }

    public function test_cross_outlet_attendance_show_returns_forbidden(): void
    {
        $this->seedHrmPermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_att_scoped__'],
            ['description' => 'Attendance scoped'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['attendance.view'])->pluck('id')->all(),
        );

        $scopedUser = User::factory()->create();
        $scopedUser->roles()->sync([$role->id]);

        $outletA = Outlet::query()->create([
            'name' => 'A', 'address' => '', 'phone' => '', 'manager' => '', 'status' => 'active', 'code' => 'a',
        ]);
        $outletB = Outlet::query()->create([
            'name' => 'B', 'address' => '', 'phone' => '', 'manager' => '', 'status' => 'active', 'code' => 'b',
        ]);
        $this->assignUserToOutlets($scopedUser, [(int) $outletA->id]);
        Passport::actingAs($scopedUser);

        [$employeeB, , $outletB] = $this->seedFixturesOnOutlet($outletB);

        $record = AttendanceRecord::query()->create([
            'outlet_id' => $outletB->id,
            'employee_id' => $employeeB->id,
            'attendance_date' => '2026-07-06',
            'status' => AttendanceRecord::STATUS_INCOMPLETE,
            'source' => AttendanceRecord::SOURCE_MANUAL,
        ]);

        $this->getJson('/api/v1/attendance/'.$record->id)->assertForbidden();
    }

    /**
     * @return array{0: Employee, 1: Shift, 2: Outlet}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Corr Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'corr-out',
        ]);

        return $this->seedFixturesOnOutlet($outlet);
    }

    /**
     * @return array{0: Employee, 1: Shift, 2: Outlet}
     */
    private function seedFixturesOnOutlet(Outlet $outlet): array
    {
        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-'.uniqid('', true),
            'full_name' => 'Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $shift = Shift::query()->create([
            'tenant_id' => 1,
            'code' => 'S1',
            'name' => 'Standard',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'late_tolerance_minutes' => 5,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        return [$employee, $shift, $outlet];
    }
}
