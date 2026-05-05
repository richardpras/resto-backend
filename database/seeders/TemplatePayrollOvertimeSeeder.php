<?php

namespace Database\Seeders;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Overtime;
use Database\Seeders\Concerns\LoadsTemplatePayrollData;
use Illuminate\Database\Seeder;

class TemplatePayrollOvertimeSeeder extends Seeder
{
    use LoadsTemplatePayrollData;

    public function run(): void
    {
        $data = $this->templatePayrollData();
        $employeesByLegacy = Employee::query()
            ->get()
            ->keyBy(fn (Employee $employee) => strtolower($employee->employee_no));

        foreach ($data['overtimes'] as $row) {
            /** @var Employee|null $employee */
            $employee = $employeesByLegacy->get(strtolower($row['employeeId']));
            if ($employee === null) {
                continue;
            }

            Overtime::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'date' => $row['date'],
                    'notes' => $row['notes'] ?? null,
                ],
                [
                    'hours' => $row['hours'],
                    'status' => $row['status'],
                    'created_by' => null,
                    'updated_by' => null,
                ],
            );
        }
    }
}
