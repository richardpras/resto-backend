<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeShiftAssignment;
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

class ShiftAssignmentTest extends TestCase
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

    public function test_create_assignment_and_current_lookup(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift] = $this->seedEmployeeAndShift();

        $created = $this->postJson('/api/v1/shift-assignments', [
            'employeeId' => $employee->id,
            'shiftId' => $shift->id,
            'effectiveFrom' => '2026-06-01',
            'effectiveUntil' => null,
            'notes' => 'Primary morning',
        ])->assertCreated()
            ->assertJsonPath('data.isActive', true)
            ->assertJsonPath('data.shift.name', 'Morning Shift');

        $history = $this->getJson('/api/v1/hr/employees/'.$employee->id.'/shift-history')
            ->assertOk()
            ->assertJsonPath('data.current.shift.name', 'Morning Shift')
            ->assertJsonCount(1, 'data.history');

        $this->assertSame((int) $created->json('data.id'), (int) $history->json('data.current.id'));
    }

    public function test_overlap_rejection_for_active_assignments(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shiftA] = $this->seedEmployeeAndShift('SHIFT-A', 'Shift A');
        $shiftB = Shift::query()->create([
            'tenant_id' => 1,
            'code' => 'SHIFT-B',
            'name' => 'Shift B',
            'start_time' => '14:00:00',
            'end_time' => '22:00:00',
            'late_tolerance_minutes' => 5,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        $this->postJson('/api/v1/shift-assignments', [
            'employeeId' => $employee->id,
            'shiftId' => $shiftA->id,
            'effectiveFrom' => '2026-01-01',
            'effectiveUntil' => '2026-12-31',
        ])->assertCreated();

        $this->postJson('/api/v1/shift-assignments', [
            'employeeId' => $employee->id,
            'shiftId' => $shiftB->id,
            'effectiveFrom' => '2026-06-01',
            'effectiveUntil' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['effectiveFrom']);
    }

    public function test_deactivate_assignment(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift] = $this->seedEmployeeAndShift();

        $row = $this->postJson('/api/v1/shift-assignments', [
            'employeeId' => $employee->id,
            'shiftId' => $shift->id,
            'effectiveFrom' => '2026-06-01',
        ])->assertCreated();

        $this->patchJson('/api/v1/shift-assignments/'.$row->json('data.id').'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.isActive', false);

        $history = $this->getJson('/api/v1/hr/employees/'.$employee->id.'/shift-history')->assertOk();
        $this->assertNull($history->json('data.current'));
        $this->assertFalse((bool) $history->json('data.history.0.isActive'));
    }

    public function test_assignment_history_lists_all_rows(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift] = $this->seedEmployeeAndShift();

        $this->postJson('/api/v1/shift-assignments', [
            'employeeId' => $employee->id,
            'shiftId' => $shift->id,
            'effectiveFrom' => '2025-01-01',
            'effectiveUntil' => '2025-12-31',
            'isActive' => false,
        ])->assertCreated();

        $this->postJson('/api/v1/shift-assignments', [
            'employeeId' => $employee->id,
            'shiftId' => $shift->id,
            'effectiveFrom' => '2026-06-01',
        ])->assertCreated();

        $this->getJson('/api/v1/hr/employees/'.$employee->id.'/shift-history')
            ->assertOk()
            ->assertJsonCount(2, 'data.history');
    }

    public function test_list_is_scoped_to_user_outlets(): void
    {
        $this->seedHrmPermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_shift_scoped__'],
            ['description' => 'Shift viewer scoped to one outlet'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['shift.view', 'shift.manage'])->pluck('id')->all(),
        );

        $scopedUser = User::factory()->create();
        $scopedUser->roles()->sync([$role->id]);

        $outletA = $this->createOutlet('Outlet A', 'out-a');
        $outletB = $this->createOutlet('Outlet B', 'out-b');
        $this->assignUserToOutlets($scopedUser, [(int) $outletA->id]);
        Passport::actingAs($scopedUser);

        [$empA, $shiftA] = $this->seedEmployeeAndShift('SA', 'Morning', $outletA);
        [$empB, $shiftB] = $this->seedEmployeeAndShift('SB', 'Evening', $outletB);

        EmployeeShiftAssignment::query()->create([
            'outlet_id' => $outletA->id,
            'employee_id' => $empA->id,
            'shift_id' => $shiftA->id,
            'effective_from' => '2026-06-01',
            'is_active' => true,
        ]);
        EmployeeShiftAssignment::query()->create([
            'outlet_id' => $outletB->id,
            'employee_id' => $empB->id,
            'shift_id' => $shiftB->id,
            'effective_from' => '2026-06-01',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/shift-assignments')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_shift_routes_require_permissions(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->getJson('/api/v1/shift-assignments')->assertForbidden();
        $this->postJson('/api/v1/shift-assignments', [])->assertForbidden();
    }

    public function test_cross_outlet_shift_history_is_denied_for_scoped_user(): void
    {
        $this->seedHrmPermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_shift_history_scoped__'],
            ['description' => 'Shift history scoped'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['shift.view', 'employees.view'])->pluck('id')->all(),
        );

        $scopedUser = User::factory()->create();
        $scopedUser->roles()->sync([$role->id]);

        $outletA = $this->createOutlet('Scoped A', 'sc-a');
        $outletB = $this->createOutlet('Scoped B', 'sc-b');
        $this->assignUserToOutlets($scopedUser, [(int) $outletA->id]);
        Passport::actingAs($scopedUser);

        [$empB] = $this->seedEmployeeAndShift('SB2', 'Night', $outletB);

        $this->getJson('/api/v1/hr/employees/'.$empB->id.'/shift-history')
            ->assertUnprocessable();
    }

    /**
     * @return array{0: Employee, 1: Shift}
     */
    private function seedEmployeeAndShift(
        string $shiftCode = 'SHIFT-MORN',
        string $shiftName = 'Morning Shift',
        ?Outlet $outlet = null,
    ): array {
        $outlet ??= $this->createOutlet('Test Outlet', 'test-out');

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-'.uniqid('', true),
            'full_name' => 'Shift Worker',
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
