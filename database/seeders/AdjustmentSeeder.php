<?php

namespace Database\Seeders;

use App\Models\Modules\HR\Domain\Adjustment;
use App\Models\Modules\HR\Domain\Employee;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AdjustmentSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::query()->get()->keyBy('employee_no');
        $month = Carbon::now()->format('Y-m');

        $rows = [
            ['employee_no' => 'EMP-PAY-001', 'type' => 'allowance', 'category' => 'transport', 'amount' => 250000, 'date' => $month.'-10', 'notes' => 'Tunjangan transport bulanan'],
            ['employee_no' => 'EMP-PAY-002', 'type' => 'allowance', 'category' => 'meal', 'amount' => 300000, 'date' => $month.'-10', 'notes' => 'Tunjangan makan dapur'],
            ['employee_no' => 'EMP-PAY-004', 'type' => 'allowance', 'category' => 'bonus', 'amount' => 500000, 'date' => $month.'-20', 'notes' => 'Bonus target outlet'],
            ['employee_no' => 'EMP-PAY-009', 'type' => 'deduction', 'category' => 'lateness', 'amount' => 120000, 'date' => $month.'-25', 'notes' => 'Akumulasi keterlambatan'],
            ['employee_no' => 'EMP-PAY-003', 'type' => 'deduction', 'category' => 'penalty', 'amount' => 150000, 'date' => $month.'-25', 'notes' => 'Sanksi absensi'],
            ['employee_no' => 'EMP-PAY-010', 'type' => 'allowance', 'category' => 'meal', 'amount' => 180000, 'date' => $month.'-12', 'notes' => 'Tunjangan makan shift malam'],
        ];

        foreach ($rows as $row) {
            $employee = $employees->get($row['employee_no']);
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
                    'notes' => $row['notes'],
                    'created_by' => null,
                    'updated_by' => null,
                ],
            );
        }
    }
}
