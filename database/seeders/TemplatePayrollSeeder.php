<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TemplatePayrollSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TemplatePayrollEmployeesSeeder::class);
        $this->call(TemplatePayrollShiftsSeeder::class);
    }
}
