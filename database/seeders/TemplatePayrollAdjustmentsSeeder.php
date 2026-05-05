<?php

namespace Database\Seeders;

use App\Models\Modules\HR\Domain\Adjustment;
use App\Models\Modules\HR\Domain\Employee;
use Database\Seeders\Concerns\LoadsTemplatePayrollData;
use Illuminate\Database\Seeder;

class TemplatePayrollAdjustmentsSeeder extends Seeder
{
    use LoadsTemplatePayrollData;

    public function run(): void
    {
        $data = $this->templatePayrollData();
        $employeesByLegacy = Employee::query()
            ->get()
            ->keyBy(fn (Employee $employee) => strtolower($employee->employee_no));

        foreach ($data['adjustments'] as $row) {
            /** @var Employee|null $employee */
            $employee = $employeesByLegacy->get(strtolower($row['employeeId']));
            if ($employee === null) {
                continue;
            }

            Adjustment::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'type' => $row['type'],
                    'category' => $row['category'],
                    'amount' => $row['amount'],
                    'date' => $row['date'],
                ],
                [
                    'notes' => $row['notes'] ?? null,
                    'created_by' => null,
                    'updated_by' => null,
                ],
            );
        }
    }
}
