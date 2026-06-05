<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeTaxProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class EmployeeTaxProfileTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->actingAsHrmApiAdministrator();
    }

    public function test_upsert_employee_tax_profile(): void
    {
        $employee = $this->seedEmployee();

        $this->postJson('/api/v1/employee-tax-profiles', [
            'employeeId' => $employee->id,
            'npwpNumber' => '12.345.678.9-012.000',
            'ptkpStatus' => 'TK0',
            'pph21Enabled' => true,
        ])->assertCreated()
            ->assertJsonPath('data.ptkpStatus', 'TK0')
            ->assertJsonPath('data.pph21Enabled', true);

        $this->assertDatabaseHas('employee_tax_profiles', [
            'employee_id' => $employee->id,
            'ptkp_status' => 'TK0',
            'pph21_enabled' => true,
        ]);
    }

    public function test_update_employee_tax_profile(): void
    {
        $employee = $this->seedEmployee();
        $profile = EmployeeTaxProfile::query()->create([
            'employee_id' => $employee->id,
            'ptkp_status' => 'TK0',
            'pph21_enabled' => false,
        ]);

        $this->patchJson('/api/v1/employee-tax-profiles/'.$profile->id, [
            'ptkpStatus' => 'K1',
            'pph21Enabled' => true,
        ])->assertOk()
            ->assertJsonPath('data.ptkpStatus', 'K1')
            ->assertJsonPath('data.pph21Enabled', true);
    }

    private function seedEmployee(): Employee
    {
        $outlet = Outlet::query()->create([
            'name' => 'Tax Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'tax-out',
        ]);

        return Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-TAX-01',
            'full_name' => 'Tax Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);
    }
}
