<?php

namespace Database\Seeders;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Loan;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class LoanSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::query()->get()->keyBy('employee_no');

        $rows = [
            ['employee_no' => 'EMP-PAY-002', 'amount' => 5000000, 'installments' => 10, 'paid_installments' => 2, 'start_date' => Carbon::now()->subMonths(2)->startOfMonth()->toDateString(), 'notes' => 'Pinjaman keluarga', 'status' => 'active'],
            ['employee_no' => 'EMP-PAY-003', 'amount' => 2000000, 'installments' => 6, 'paid_installments' => 1, 'start_date' => Carbon::now()->subMonth()->startOfMonth()->toDateString(), 'notes' => 'Biaya kesehatan', 'status' => 'active'],
            ['employee_no' => 'EMP-PAY-009', 'amount' => 1500000, 'installments' => 3, 'paid_installments' => 0, 'start_date' => Carbon::now()->startOfMonth()->toDateString(), 'notes' => 'Perbaikan motor', 'status' => 'active'],
        ];

        foreach ($rows as $row) {
            $employee = $employees->get($row['employee_no']);
            if ($employee === null) {
                continue;
            }

            Loan::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'amount' => $row['amount'],
                    'start_date' => $row['start_date'],
                ],
                [
                    'installments' => $row['installments'],
                    'paid_installments' => $row['paid_installments'],
                    'notes' => $row['notes'],
                    'status' => $row['status'],
                    'created_by' => null,
                    'updated_by' => null,
                ],
            );
        }
    }
}
