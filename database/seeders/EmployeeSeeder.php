<?php

namespace Database\Seeders;

use App\Models\Modules\HR\Domain\Employee;
use Database\Seeders\Concerns\PayrollSeederData;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    use PayrollSeederData;

    public function run(): void
    {
        foreach ($this->payrollEmployees() as $row) {
            Employee::query()->updateOrCreate(
                ['employee_no' => $row['employee_no']],
                [
                    'tenant_id' => null,
                    'user_id' => null,
                    'full_name' => $row['name'],
                    'position' => $row['position'],
                    'outlet' => $row['outlet'],
                    'salary_type' => $row['salary_type'],
                    'base_salary' => $row['base_salary'],
                    'overtime_rate' => $row['overtime_rate'],
                    'hire_date' => $row['join_date'],
                    'status' => $row['status'],
                    'email' => null,
                    'phone' => null,
                    'created_by' => null,
                    'updated_by' => null,
                ],
            );
        }
    }
}
