<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
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

class EmployeeRosterTest extends TestCase
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

    public function test_create_roster_entry(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift] = $this->seedEmployeeAndShift();

        $this->postJson('/api/v1/rosters', [
            'employeeId' => $employee->id,
            'shiftId' => $shift->id,
            'rosterDate' => '2026-07-15',
            'notes' => 'Manual schedule',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.shift.name', 'Morning Shift');
    }

    public function test_unique_roster_day_validation(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift] = $this->seedEmployeeAndShift();

        $payload = [
            'employeeId' => $employee->id,
            'shiftId' => $shift->id,
            'rosterDate' => '2026-07-20',
        ];

        $this->postJson('/api/v1/rosters', $payload)->assertCreated();
        $this->postJson('/api/v1/rosters', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['rosterDate']);
    }

    public function test_employee_schedule_lookup(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift] = $this->seedEmployeeAndShift();

        EmployeeRoster::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-07-07',
            'status' => EmployeeRoster::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/hr/employees/'.$employee->id.'/schedule?weekStart=2026-07-06')
            ->assertOk()
            ->assertJsonPath('data.days.1.dayName', 'Tuesday')
            ->assertJsonPath('data.days.1.shift.name', 'Morning Shift');
    }

    public function test_list_rosters_scoped_to_user_outlets(): void
    {
        $this->seedHrmPermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_schedule_scoped__'],
            ['description' => 'Schedule viewer scoped'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['schedule.view', 'schedule.manage'])->pluck('id')->all(),
        );

        $scopedUser = User::factory()->create();
        $scopedUser->roles()->sync([$role->id]);

        $outletA = $this->createOutlet('Outlet A', 'ro-a');
        $outletB = $this->createOutlet('Outlet B', 'ro-b');
        $this->assignUserToOutlets($scopedUser, [(int) $outletA->id]);
        Passport::actingAs($scopedUser);

        [$empA, $shiftA] = $this->seedEmployeeAndShift('SA', 'Morning', $outletA);
        [$empB, $shiftB] = $this->seedEmployeeAndShift('SB', 'Evening', $outletB);

        foreach ([[$empA, $shiftA, $outletA], [$empB, $shiftB, $outletB]] as [$emp, $shift, $outlet]) {
            EmployeeRoster::query()->create([
                'outlet_id' => $outlet->id,
                'employee_id' => $emp->id,
                'shift_id' => $shift->id,
                'roster_date' => '2026-07-10',
                'status' => EmployeeRoster::STATUS_DRAFT,
            ]);
        }

        $this->getJson('/api/v1/rosters?fromDate=2026-07-01&toDate=2026-07-31')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_roster_routes_require_permissions(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson('/api/v1/rosters')->assertForbidden();
        $this->postJson('/api/v1/rosters', [])->assertForbidden();
    }

    public function test_cross_outlet_schedule_lookup_returns_forbidden(): void
    {
        $this->seedHrmPermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_schedule_history_scoped__'],
            ['description' => 'Schedule scoped'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['schedule.view', 'employees.view'])->pluck('id')->all(),
        );

        $scopedUser = User::factory()->create();
        $scopedUser->roles()->sync([$role->id]);

        $outletA = $this->createOutlet('Scoped A', 'rs-a');
        $outletB = $this->createOutlet('Scoped B', 'rs-b');
        $this->assignUserToOutlets($scopedUser, [(int) $outletA->id]);
        Passport::actingAs($scopedUser);

        [$empB] = $this->seedEmployeeAndShift('SB2', 'Night', $outletB);

        $this->getJson('/api/v1/hr/employees/'.$empB->id.'/schedule')
            ->assertForbidden();
    }

    /**
     * @return array{0: Employee, 1: Shift}
     */
    private function seedEmployeeAndShift(
        string $shiftCode = 'SHIFT-MORN',
        string $shiftName = 'Morning Shift',
        ?Outlet $outlet = null,
    ): array {
        $outlet ??= $this->createOutlet('Roster Outlet', 'roster-out');

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-'.uniqid('', true),
            'full_name' => 'Roster Worker',
            'position' => 'Staff',
            'base_salary' => 3000000,
            'status' => 'active',
        ]);

        $shift = Shift::query()->create([
            'tenant_id' => 1,
            'code' => $shiftCode,
            'name' => $shiftName,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'late_tolerance_minutes' => 10,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        return [$employee, $shift];
    }

    private function createOutlet(string $name, string $code): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => $code,
        ]);
    }
}
