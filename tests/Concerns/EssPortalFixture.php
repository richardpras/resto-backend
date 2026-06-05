<?php

namespace Tests\Concerns;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeUser;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

trait EssPortalFixture
{
    protected function setupEssPassport(): void
    {
        Artisan::call('passport:keys', ['--force' => true]);
        Artisan::call('passport:client', [
            '--personal' => true,
            '--name' => 'Tests Employee ESS Personal Access Client',
            '--provider' => 'employee_users',
            '--no-interaction' => true,
        ]);
    }

    protected function enableEssPortal(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'enable_split_bill' => true,
                'enable_multi_payment' => true,
                'confirm_before_payment' => true,
                'enable_qr_ordering' => true,
                'employee_self_service_enabled' => true,
            ],
        );
    }

    protected function disableEssPortal(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'enable_split_bill' => true,
                'enable_multi_payment' => true,
                'confirm_before_payment' => true,
                'enable_qr_ordering' => true,
                'employee_self_service_enabled' => false,
            ],
        );
    }

    /**
     * @return array{0: Employee, 1: EmployeeUser}
     */
    protected function seedEmployeePortalUser(string $email = 'ess.worker@test.local', string $password = 'secret123'): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'ESS Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ess-out-'.uniqid('', true),
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-ESS-01',
            'full_name' => 'ESS Worker',
            'email' => $email,
            'position' => 'Staff',
            'base_salary' => 4000000,
            'status' => 'active',
        ]);

        $user = EmployeeUser::query()->create([
            'employee_id' => $employee->id,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        return [$employee, $user];
    }
}
