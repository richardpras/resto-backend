<?php

namespace Database\Seeders;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Overtime;
use Carbon\Carbon;
use Database\Seeders\Concerns\PayrollSeederData;
use Illuminate\Database\Seeder;

class OvertimeSeeder extends Seeder
{
    use PayrollSeederData;

    public function run(): void
    {
        $employees = Employee::query()->get()->keyBy('employee_no');
        $month = Carbon::now()->format('Y-m');

        $rows = [
            // Heavy overtime employee
            ['employee_no' => 'EMP-PAY-002', 'date' => $month.'-05', 'hours' => 4, 'status' => 'approved', 'notes' => 'Persiapan promo akhir pekan'],
            ['employee_no' => 'EMP-PAY-002', 'date' => $month.'-12', 'hours' => 3.5, 'status' => 'approved', 'notes' => 'Closing dapur'],
            ['employee_no' => 'EMP-PAY-002', 'date' => $month.'-19', 'hours' => 4, 'status' => 'approved', 'notes' => 'Peak hour support'],
            ['employee_no' => 'EMP-PAY-007', 'date' => $month.'-08', 'hours' => 2, 'status' => 'pending', 'notes' => 'Menunggu approval supervisor'],
            ['employee_no' => 'EMP-PAY-004', 'date' => $month.'-15', 'hours' => 1.5, 'status' => 'approved', 'notes' => 'Audit operasional'],
            ['employee_no' => 'EMP-PAY-009', 'date' => $month.'-22', 'hours' => 1, 'status' => 'approved', 'notes' => 'Backup kasir malam'],
        ];

        foreach ($rows as $row) {
            $employee = $employees->get($row['employee_no']);
            if ($employee === null) {
                continue;
            }

            Overtime::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'date' => $row['date'],
                    'notes' => $row['notes'],
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
