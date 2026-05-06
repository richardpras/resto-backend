<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(UserManagementPermissionsSeeder::class);

        $this->call(AppSettingsFromTemplateSeeder::class);
        $this->call(SettingsDomainFromTemplateSeeder::class);
        $this->call(TemplateInventoryMenuSeeder::class);
        $this->call(TemplateAccountingSeeder::class);
        $this->call(TemplatePayrollSeeder::class);
        $this->call(EmployeeSeeder::class);
        $this->call(AttendanceSeeder::class);
        $this->call(OvertimeSeeder::class);
        $this->call(AdjustmentSeeder::class);
        $this->call(LoanSeeder::class);

        $this->call(TemplateDemoUsersSeeder::class);

        $this->call(TemplateMembersSuppliersSeeder::class);
        $this->call(PurchaseFlowSeeder::class);
    }
}
