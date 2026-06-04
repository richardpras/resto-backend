<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Loan;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class HrmFoundation02Test extends TestCase
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

    public function test_hr_employee_create_syncs_outlet_and_position_labels(): void
    {
        $this->actingAsHrmApiAdministrator();

        $outlet = Outlet::query()->create([
            'name' => 'Foundation Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'found-outlet',
        ]);

        $dept = $this->postJson('/api/v1/departments', [
            'outletId' => (int) $outlet->id,
            'code' => 'OPS',
            'name' => 'Operations',
        ])->assertCreated();

        $position = $this->postJson('/api/v1/positions', [
            'outletId' => (int) $outlet->id,
            'departmentId' => (int) $dept->json('data.id'),
            'code' => 'WAITER',
            'name' => 'Waiter',
        ])->assertCreated();

        $employee = $this->postJson('/api/v1/hr/employees', [
            'outletId' => (int) $outlet->id,
            'employeeNo' => 'EMP-FOUND-01',
            'fullName' => 'Normalized Worker',
            'positionId' => (int) $position->json('data.id'),
            'position' => 'Legacy Label',
            'baseSalary' => 3000000,
        ])->assertCreated();

        $this->assertSame((int) $outlet->id, (int) $employee->json('data.outletId'));
        $this->assertSame('Foundation Outlet', $employee->json('data.outlet'));
        $this->assertSame('Waiter', $employee->json('data.position'));
    }

    public function test_loans_list_is_scoped_to_user_outlets(): void
    {
        $this->seedHrmPermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loans_scoped__'],
            ['description' => 'Loans viewer without tenant-wide outlet access'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['loans.view'])->pluck('id')->all(),
        );

        $scopedUser = User::factory()->create();
        $scopedUser->roles()->sync([$role->id]);

        $outletA = Outlet::query()->create([
            'name' => 'Outlet A',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'out-a',
        ]);
        $outletB = Outlet::query()->create([
            'name' => 'Outlet B',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'out-b',
        ]);

        $empA = Employee::query()->create([
            'outlet_id' => $outletA->id,
            'employee_no' => 'EMP-A',
            'full_name' => 'A',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);
        Employee::query()->create([
            'outlet_id' => $outletB->id,
            'employee_no' => 'EMP-B',
            'full_name' => 'B',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        Loan::query()->create([
            'employee_id' => $empA->id,
            'amount' => 100000,
            'installments' => 2,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        Loan::query()->create([
            'employee_id' => Employee::query()->where('employee_no', 'EMP-B')->value('id'),
            'amount' => 200000,
            'installments' => 2,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);

        $this->assignUserToOutlets($scopedUser, [(int) $outletA->id]);
        \Laravel\Passport\Passport::actingAs($scopedUser);

        $this->getJson('/api/v1/loans')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_hr_routes_reject_users_without_hrm_permissions(): void
    {
        $user = User::factory()->create();
        \Laravel\Passport\Passport::actingAs($user);

        $this->getJson('/api/v1/hr/employees')->assertForbidden();
        $this->getJson('/api/v1/loans')->assertForbidden();
    }
}
