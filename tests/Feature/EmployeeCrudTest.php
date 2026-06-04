<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class EmployeeCrudTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_show_update_delete_require_authentication(): void
    {
        $employee = Employee::query()->create([
            'employee_no' => 'EMP-AUTH-CHK',
            'full_name' => 'Auth Check',
            'position' => 'Staff',
            'base_salary' => 3000000,
        ]);

        $this->getJson('/api/v1/hr/employees/'.$employee->id)->assertUnauthorized();
        $this->putJson('/api/v1/hr/employees/'.$employee->id, [])->assertUnauthorized();
        $this->deleteJson('/api/v1/hr/employees/'.$employee->id)->assertUnauthorized();
    }

    public function test_show_returns_404_when_missing(): void
    {
        $this->authenticateUser();

        $this->getJson('/api/v1/hr/employees/99999')->assertNotFound();
    }

    public function test_show_returns_employee_resource(): void
    {
        $this->authenticateUser();

        $employee = Employee::query()->create([
            'tenant_id' => 1,
            'employee_no' => 'EMP-SHOW-01',
            'full_name' => 'Show Me',
            'email' => 'show@example.com',
            'position' => 'Chef',
            'base_salary' => 5500000,
            'hire_date' => '2026-01-15',
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/hr/employees/'.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.employeeNo', 'EMP-SHOW-01')
            ->assertJsonPath('data.fullName', 'Show Me')
            ->assertJsonPath('data.email', 'show@example.com')
            ->assertJsonPath('data.baseSalary', 5500000);
    }

    public function test_update_returns_404_when_missing(): void
    {
        $this->authenticateUser();

        $this->putJson('/api/v1/hr/employees/99999', [
            'employeeNo' => 'X',
            'fullName' => 'X',
            'position' => 'X',
            'baseSalary' => 1,
        ])->assertNotFound();
    }

    public function test_update_validates_unique_employee_no_against_other_rows(): void
    {
        $this->authenticateUser();

        Employee::query()->create([
            'employee_no' => 'EMP-UNIQ-A',
            'full_name' => 'A',
            'position' => 'Staff',
            'base_salary' => 1000000,
        ]);
        $target = Employee::query()->create([
            'employee_no' => 'EMP-UNIQ-B',
            'full_name' => 'B',
            'position' => 'Staff',
            'base_salary' => 2000000,
        ]);

        $this->putJson('/api/v1/hr/employees/'.$target->id, [
            'employeeNo' => 'EMP-UNIQ-A',
            'fullName' => 'B',
            'position' => 'Staff',
            'baseSalary' => 2000000,
        ])->assertUnprocessable();
    }

    public function test_update_persists_changes(): void
    {
        $this->authenticateUser();

        $employee = Employee::query()->create([
            'employee_no' => 'EMP-UPD-01',
            'full_name' => 'Before',
            'position' => 'Waiter',
            'base_salary' => 4000000,
            'status' => 'active',
        ]);

        $this->patchJson('/api/v1/hr/employees/'.$employee->id, [
            'employeeNo' => 'EMP-UPD-01',
            'fullName' => 'After Name',
            'email' => 'after@example.com',
            'phone' => '08123456789',
            'position' => 'Senior Waiter',
            'baseSalary' => 4500000,
            'hireDate' => '2026-02-01',
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.fullName', 'After Name')
            ->assertJsonPath('data.position', 'Senior Waiter')
            ->assertJsonPath('data.baseSalary', 4500000)
            ->assertJsonPath('data.status', 'inactive');

        $employee->refresh();
        $this->assertSame('After Name', $employee->full_name);
        $this->assertSame('inactive', $employee->status);
    }

    public function test_destroy_returns_404_when_missing(): void
    {
        $this->authenticateUser();

        $this->deleteJson('/api/v1/hr/employees/99999')->assertNotFound();
    }

    public function test_destroy_returns_conflict_when_attendance_exists(): void
    {
        $this->authenticateUser();

        $employee = Employee::query()->create([
            'employee_no' => 'EMP-DEL-BLOCK',
            'full_name' => 'Blocked',
            'position' => 'Staff',
            'base_salary' => 1000000,
        ]);

        Attendance::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => null,
            'attendance_date' => '2026-05-01',
            'check_in' => null,
            'check_out' => null,
            'source' => 'manual',
            'status' => 'absent',
            'sync_key' => 'crud-block-delete-1',
        ]);

        $this->deleteJson('/api/v1/hr/employees/'.$employee->id)->assertStatus(409);
    }

    public function test_destroy_removes_employee_when_no_blocking_rows(): void
    {
        $this->authenticateUser();

        $employee = Employee::query()->create([
            'employee_no' => 'EMP-DEL-OK',
            'full_name' => 'Gone',
            'position' => 'Staff',
            'base_salary' => 1000000,
        ]);

        $this->deleteJson('/api/v1/hr/employees/'.$employee->id)->assertOk();

        $this->assertNull(Employee::query()->find($employee->id));
    }

    private function authenticateUser(): User
    {
        return $this->actingAsHrmApiAdministrator();
    }
}
