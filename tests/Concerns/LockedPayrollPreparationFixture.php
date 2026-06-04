<?php

namespace Tests\Concerns;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\Settings\Domain\Outlet;

trait LockedPayrollPreparationFixture
{
    /**
     * @return array{0: Employee, 1: PayrollPreparationPeriod}
     */
    protected function seedLockedPreparationWithEmployee(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Locked Prep',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lock-prep',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-ENG-01',
            'full_name' => 'Engine Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $period = PayrollPreparationPeriod::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => PayrollPreparationPeriod::STATUS_LOCKED,
            'generated_at' => now(),
            'locked_at' => now(),
        ]);

        return [$employee, $period];
    }
}
