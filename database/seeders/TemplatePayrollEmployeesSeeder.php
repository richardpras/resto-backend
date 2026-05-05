<?php

namespace Database\Seeders;

use App\Models\Modules\HR\Domain\Employee;
use Database\Seeders\Concerns\LoadsTemplatePayrollData;
use Illuminate\Database\Seeder;

class TemplatePayrollEmployeesSeeder extends Seeder
{
    use LoadsTemplatePayrollData;

    public function run(): void
    {
        $data = $this->templatePayrollData();

        foreach ($data['employees'] as $row) {
            Employee::query()->updateOrCreate(
                ['employee_no' => strtoupper($row['id'])],
                [
                    'tenant_id' => null,
                    'user_id' => null,
                    'full_name' => $row['name'],
                    'email' => null,
                    'phone' => null,
                    'position' => $row['position'],
                    'outlet' => $row['outlet'],
                    'salary_type' => $row['salaryType'],
                    'base_salary' => $row['baseSalary'],
                    'overtime_rate' => $row['overtimeRate'],
                    'hire_date' => $row['joinDate'],
                    'status' => $row['status'],
                    'created_by' => null,
                    'updated_by' => null,
                ],
            );
        }
    }
}
