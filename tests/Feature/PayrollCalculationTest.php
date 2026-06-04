<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\TestCase;

class PayrollCalculationTest extends TestCase
{
    use HrmApiFixture;
    use LockedPayrollPreparationFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_calculate_gross_and_net_from_profile(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 5000000,
            'default_allowance' => 500000,
            'default_deduction' => 200000,
        ]);

        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'scheduled_days' => 22,
            'attended_days' => 20,
            'absent_days' => 2,
            'late_minutes' => 30,
            'early_leave_minutes' => 0,
            'leave_days' => 1,
            'paid_leave_days' => 1,
            'unpaid_leave_days' => 0,
            'overtime_minutes' => 120,
            'overtime_hours' => 2,
            'review_required' => false,
        ]);

        $journalCountBefore = \Illuminate\Support\Facades\DB::table('journal_entries')->count();

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $runId = (int) $runRes->json('data.id');

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        $item = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->first();
        $this->assertNotNull($item);
        $this->assertEquals(5500000.0, (float) $item->gross_salary);
        $this->assertEquals(200000.0, (float) $item->total_deductions);
        $this->assertEquals(5300000.0, (float) $item->net_salary);
        $this->assertEquals(20, (int) $item->attendance_days);
        $this->assertEquals(2.0, (float) $item->overtime_hours);
        $this->assertEquals(1.0, (float) $item->leave_days);

        $this->assertSame($journalCountBefore, \Illuminate\Support\Facades\DB::table('journal_entries')->count());
    }
}
