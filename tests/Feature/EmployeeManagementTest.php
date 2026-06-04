<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Department;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Position;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\OrganizationStructureTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use OrganizationStructureTestFixtures;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_employee_crud_search_and_outlet_isolation(): void
    {
        $admin = $this->actingAsOrganizationAdmin();
        $outletA = $this->createOrganizationOutlet('A');
        $outletB = $this->createOrganizationOutlet('B');
        $this->assignUserToOutlets($admin, [(int) $outletA->id]);

        $department = Department::query()->create([
            'outlet_id' => $outletA->id,
            'code' => 'KIT',
            'name' => 'Kitchen',
            'is_active' => true,
        ]);
        $position = Position::query()->create([
            'outlet_id' => $outletA->id,
            'department_id' => $department->id,
            'code' => 'CHEF',
            'name' => 'Chef',
            'is_active' => true,
        ]);

        $create = $this->postJson('/api/v1/employees', [
            'outletId' => (int) $outletA->id,
            'employeeNo' => 'EMP-A-001',
            'fullName' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '0812000111',
            'positionId' => (int) $position->id,
            'departmentId' => (int) $department->id,
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.fullName', 'Budi Santoso')
            ->assertJsonPath('data.positionName', 'Chef');

        $id = (int) $create->json('data.id');

        Employee::query()->create([
            'outlet_id' => $outletB->id,
            'employee_no' => 'EMP-B-001',
            'full_name' => 'Other Outlet',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/employees?outletId='.(int) $outletA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/employees?outletId='.(int) $outletA->id.'&search=budi')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/employees/'.$id)
            ->assertOk()
            ->assertJsonPath('data.employeeNo', 'EMP-A-001');

        $this->patchJson('/api/v1/employees/'.$id, [
            'fullName' => 'Budi S.',
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.fullName', 'Budi S.')
            ->assertJsonPath('data.status', 'inactive');

        $this->postJson('/api/v1/employees', [
            'outletId' => (int) $outletA->id,
            'employeeNo' => 'EMP-A-001',
            'fullName' => 'Duplicate No',
        ])->assertStatus(422);

        $scopedRole = Role::query()->firstOrCreate(
            ['name' => '__test_org_employee_scoped__'],
            ['description' => 'users.manage only'],
        );
        $scopedRole->permissions()->sync(
            Permission::query()->where('code', 'users.manage')->pluck('id')->all(),
        );
        $scopedUser = User::factory()->create([
            'email' => 'org-scoped-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $scopedUser->roles()->sync([$scopedRole->id]);
        $this->assignUserToOutlets($scopedUser, [(int) $outletA->id]);
        \Laravel\Passport\Passport::actingAs($scopedUser);

        $this->getJson('/api/v1/employees?outletId='.(int) $outletB->id)
            ->assertStatus(422);
    }

    public function test_employee_list_requires_outlet_id(): void
    {
        $this->actingAsOrganizationAdmin();

        $this->getJson('/api/v1/employees')->assertStatus(422);
    }
}
