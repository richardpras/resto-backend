<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TemplatePayrollSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TemplatePayrollEmployeesSeeder::class);
        $this->call(TemplatePayrollShiftsSeeder::class);
        $this->call(TemplatePayrollAttendanceSeeder::class);
        $this->call(TemplatePayrollOvertimeSeeder::class);
        $this->call(TemplatePayrollAdjustmentsSeeder::class);
        $this->call(TemplatePayrollLoansSeeder::class);
    }
}
