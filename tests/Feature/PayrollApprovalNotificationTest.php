<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\Notifications\Services\ApprovalNotificationService;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\LockedPayrollPreparationFixture;
use Tests\TestCase;

class PayrollApprovalNotificationTest extends TestCase
{
    use LockedPayrollPreparationFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    public function test_calculate_notifies_payroll_approvers(): void
    {
        [$employee, $period, $calculator, $approver, $runId] = $this->seedCalculatedRunUsers();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $approver->id,
            'source_module' => UserNotification::MODULE_PAYROLL,
            'source_type' => ApprovalNotificationService::TYPE_PAYROLL_RUN_PENDING,
            'source_id' => (string) $runId,
            'severity' => UserNotification::SEVERITY_INFO,
            'action_url' => '/payroll?run='.$runId,
        ]);

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $calculator->id,
            'source_type' => ApprovalNotificationService::TYPE_PAYROLL_RUN_PENDING,
        ]);
    }

    public function test_approve_notifies_calculator_with_success(): void
    {
        [, , $calculator, $approver, $runId] = $this->seedCalculatedRunUsers();

        Passport::actingAs($approver);
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/approve')->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $calculator->id,
            'source_type' => ApprovalNotificationService::TYPE_PAYROLL_RUN_APPROVED,
            'severity' => UserNotification::SEVERITY_SUCCESS,
        ]);
    }

    public function test_reject_notifies_calculator_with_warning(): void
    {
        [, , $calculator, $approver, $runId] = $this->seedCalculatedRunUsers();

        Passport::actingAs($approver);
        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/reject')->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $calculator->id,
            'source_type' => ApprovalNotificationService::TYPE_PAYROLL_RUN_REJECTED,
            'severity' => UserNotification::SEVERITY_WARNING,
        ]);
    }

    /** @return array{0:\App\Models\Modules\HR\Domain\Employee,1:\App\Models\Modules\HR\Domain\PayrollPreparationPeriod,2:User,3:User,4:int} */
    private function seedCalculatedRunUsers(): array
    {
        [$employee, $period] = $this->seedLockedPreparationWithEmployee();
        $outlet = Outlet::query()->findOrFail($period->outlet_id);

        EmployeeSalaryProfile::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => 4000000,
            'default_allowance' => 0,
            'default_deduction' => 0,
        ]);
        PayrollPreparationSnapshot::query()->create([
            'preparation_period_id' => $period->id,
            'employee_id' => $employee->id,
            'review_required' => false,
        ]);

        $calculator = $this->createPayrollUser($outlet, 'calc');
        $approver = $this->createPayrollUser($outlet, 'apr');

        Passport::actingAs($calculator);
        $runId = (int) $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')->assertOk();

        return [$employee, $period, $calculator, $approver, $runId];
    }

    private function createPayrollUser(Outlet $outlet, string $suffix): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_payroll_notify_'.$suffix.'__'],
            ['description' => 'Payroll notify'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['payroll.manage', 'payroll.view'])->pluck('id')->all(),
        );

        $user = User::factory()->create([
            'email' => 'payroll-notify-'.$suffix.'-'.uniqid().'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);
        $user->outlets()->sync([(int) $outlet->id]);

        return $user;
    }
}
