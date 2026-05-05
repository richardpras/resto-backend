<?php

namespace Database\Seeders;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Loan;
use Database\Seeders\Concerns\LoadsTemplatePayrollData;
use Illuminate\Database\Seeder;

class TemplatePayrollLoansSeeder extends Seeder
{
    use LoadsTemplatePayrollData;

    public function run(): void
    {
        $data = $this->templatePayrollData();
        $employeesByLegacy = Employee::query()
            ->get()
            ->keyBy(fn (Employee $employee) => strtolower($employee->employee_no));

        foreach ($data['loans'] as $row) {
            /** @var Employee|null $employee */
            $employee = $employeesByLegacy->get(strtolower($row['employeeId']));
            if ($employee === null) {
                continue;
            }

            Loan::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'start_date' => $row['startDate'],
                    'amount' => $row['amount'],
                ],
                [
                    'installments' => $row['installments'],
                    'paid_installments' => $row['paidInstallments'],
                    'notes' => $row['notes'] ?? null,
                    'status' => $row['status'],
                    'created_by' => null,
                    'updated_by' => null,
                ],
            );
        }
    }
}
