<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollSalaryProfileTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_and_list_salary_profile(): void
    {
        $this->actingAsHrmApiAdministrator();
        $employee = $this->seedEmployee();

        $this->postJson('/api/v1/salary-profiles', [
            'employeeId' => $employee->id,
            'basicSalary' => 5000000,
            'defaultAllowance' => 500000,
            'defaultDeduction' => 100000,
        ])->assertCreated()
            ->assertJsonPath('data.basicSalary', 5000000);

        $this->getJson('/api/v1/salary-profiles?employeeId='.$employee->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_duplicate_profile_rejected(): void
    {
        $this->actingAsHrmApiAdministrator();
        $employee = $this->seedEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 1000,
        ]);

        $this->postJson('/api/v1/salary-profiles', [
            'employeeId' => $employee->id,
            'basicSalary' => 2000,
        ])->assertStatus(422);
    }

    private function seedEmployee(): Employee
    {
        $outlet = Outlet::query()->create([
            'name' => 'Profile Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'prof-out',
        ]);

        return Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-PROF-01',
            'full_name' => 'Profile Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);
    }
}
