<?php

namespace Database\Seeders\Concerns;

use Carbon\Carbon;

trait PayrollSeederData
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function payrollEmployees(): array
    {
        return [
            ['id' => 'emp-001', 'employee_no' => 'EMP-PAY-001', 'name' => 'Andi Pratama', 'position' => 'Cashier', 'outlet' => 'Main', 'join_date' => Carbon::now()->subMonths(20)->toDateString(), 'salary_type' => 'monthly', 'base_salary' => 4200000, 'overtime_rate' => 25000, 'status' => 'active'],
            ['id' => 'emp-002', 'employee_no' => 'EMP-PAY-002', 'name' => 'Siti Rahmawati', 'position' => 'Chef', 'outlet' => 'Main', 'join_date' => Carbon::now()->subMonths(18)->toDateString(), 'salary_type' => 'monthly', 'base_salary' => 8500000, 'overtime_rate' => 50000, 'status' => 'active'],
            ['id' => 'emp-003', 'employee_no' => 'EMP-PAY-003', 'name' => 'Budi Santoso', 'position' => 'Waiter', 'outlet' => 'Main', 'join_date' => Carbon::now()->subMonths(14)->toDateString(), 'salary_type' => 'daily', 'base_salary' => 180000, 'overtime_rate' => 30000, 'status' => 'active'],
            ['id' => 'emp-004', 'employee_no' => 'EMP-PAY-004', 'name' => 'Rina Anggraini', 'position' => 'Manager', 'outlet' => 'Main', 'join_date' => Carbon::now()->subMonths(24)->toDateString(), 'salary_type' => 'monthly', 'base_salary' => 9800000, 'overtime_rate' => 70000, 'status' => 'active'],
            ['id' => 'emp-005', 'employee_no' => 'EMP-PAY-005', 'name' => 'Dewi Lestari', 'position' => 'Admin', 'outlet' => 'Main', 'join_date' => Carbon::now()->subMonths(16)->toDateString(), 'salary_type' => 'monthly', 'base_salary' => 5000000, 'overtime_rate' => 35000, 'status' => 'active'],
            ['id' => 'emp-006', 'employee_no' => 'EMP-PAY-006', 'name' => 'Fajar Hidayat', 'position' => 'Cashier', 'outlet' => 'Branch 1', 'join_date' => Carbon::now()->subMonths(11)->toDateString(), 'salary_type' => 'monthly', 'base_salary' => 3900000, 'overtime_rate' => 23000, 'status' => 'active'],
            ['id' => 'emp-007', 'employee_no' => 'EMP-PAY-007', 'name' => 'Nadia Putri', 'position' => 'Chef', 'outlet' => 'Branch 1', 'join_date' => Carbon::now()->subMonths(13)->toDateString(), 'salary_type' => 'monthly', 'base_salary' => 7800000, 'overtime_rate' => 48000, 'status' => 'active'],
            ['id' => 'emp-008', 'employee_no' => 'EMP-PAY-008', 'name' => 'Arif Nugroho', 'position' => 'Waiter', 'outlet' => 'Branch 1', 'join_date' => Carbon::now()->subMonths(9)->toDateString(), 'salary_type' => 'daily', 'base_salary' => 160000, 'overtime_rate' => 25000, 'status' => 'active'],
            ['id' => 'emp-009', 'employee_no' => 'EMP-PAY-009', 'name' => 'Yuni Kartika', 'position' => 'Cashier', 'outlet' => 'Main', 'join_date' => Carbon::now()->subMonths(8)->toDateString(), 'salary_type' => 'hourly', 'base_salary' => 28000, 'overtime_rate' => 22000, 'status' => 'active'],
            ['id' => 'emp-010', 'employee_no' => 'EMP-PAY-010', 'name' => 'Rizky Maulana', 'position' => 'Waiter', 'outlet' => 'Branch 1', 'join_date' => Carbon::now()->subMonths(7)->toDateString(), 'salary_type' => 'hourly', 'base_salary' => 22000, 'overtime_rate' => 20000, 'status' => 'active'],
            ['id' => 'emp-011', 'employee_no' => 'EMP-PAY-011', 'name' => 'Maya Sari', 'position' => 'Admin', 'outlet' => 'Branch 1', 'join_date' => Carbon::now()->subMonths(10)->toDateString(), 'salary_type' => 'monthly', 'base_salary' => 4700000, 'overtime_rate' => 32000, 'status' => 'inactive'],
            ['id' => 'emp-012', 'employee_no' => 'EMP-PAY-012', 'name' => 'Agus Setiawan', 'position' => 'Chef', 'outlet' => 'Main', 'join_date' => Carbon::now()->subMonths(22)->toDateString(), 'salary_type' => 'monthly', 'base_salary' => 9000000, 'overtime_rate' => 55000, 'status' => 'inactive'],
        ];
    }
}
