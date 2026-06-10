<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\OvertimeType;
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
use Tests\TestCase;

class OvertimeApprovalNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    public function test_create_and_approve_overtime_notifications(): void
    {
        $ctx = $this->seedOvertimeActors();

        Passport::actingAs($ctx['requester']);
        $otId = (int) $this->postJson('/api/v1/overtime-requests', [
            'employeeId' => $ctx['employee']->id,
            'overtimeTypeId' => $ctx['overtimeType']->id,
            'overtimeDate' => '2026-11-15',
            'startTime' => '18:00',
            'endTime' => '20:00',
            'reason' => 'Closing shift',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $ctx['manager']->id,
            'source_module' => UserNotification::MODULE_HR,
            'source_type' => ApprovalNotificationService::TYPE_OVERTIME_REQUEST_PENDING,
            'source_id' => (string) $otId,
            'action_url' => '/hr/overtime?id='.$otId,
        ]);

        Passport::actingAs($ctx['manager']);
        $this->patchJson('/api/v1/overtime-requests/'.$otId.'/approve')->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $ctx['employeeUser']->id,
            'source_type' => ApprovalNotificationService::TYPE_OVERTIME_REQUEST_APPROVED,
            'severity' => UserNotification::SEVERITY_SUCCESS,
        ]);
    }

    public function test_reject_overtime_notifies_employee(): void
    {
        $ctx = $this->seedOvertimeActors();

        Passport::actingAs($ctx['requester']);
        $otId = (int) $this->postJson('/api/v1/overtime-requests', [
            'employeeId' => $ctx['employee']->id,
            'overtimeTypeId' => $ctx['overtimeType']->id,
            'overtimeDate' => '2026-11-16',
            'startTime' => '19:00',
            'endTime' => '21:00',
        ])->assertCreated()->json('data.id');

        Passport::actingAs($ctx['manager']);
        $this->patchJson('/api/v1/overtime-requests/'.$otId.'/reject', [
            'rejectionReason' => 'Not required',
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $ctx['employeeUser']->id,
            'source_type' => ApprovalNotificationService::TYPE_OVERTIME_REQUEST_REJECTED,
            'severity' => UserNotification::SEVERITY_WARNING,
        ]);
    }

    /** @return array{employee:Employee,employeeUser:User,requester:User,manager:User,overtimeType:OvertimeType} */
    private function seedOvertimeActors(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'OT Notify Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ot-notify',
        ]);

        $employeeUser = User::factory()->create(['email' => 'emp-ot-'.uniqid().'@test.local']);
        $employeeUser->outlets()->sync([(int) $outlet->id]);

        $employee = Employee::query()->create([
            'user_id' => $employeeUser->id,
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-OT-01',
            'full_name' => 'OT Employee',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $overtimeType = OvertimeType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'weekday',
            'name' => 'Weekday OT',
            'multiplier' => 1.5,
            'is_active' => true,
        ]);

        $requester = $this->createOtUser($outlet, 'req');
        $manager = $this->createOtUser($outlet, 'mgr');

        return compact('employee', 'employeeUser', 'requester', 'manager', 'overtimeType');
    }

    private function createOtUser(Outlet $outlet, string $suffix): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_ot_notify_'.$suffix.'__'],
            ['description' => 'OT notify'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['overtime.manage'])->pluck('id')->all(),
        );

        $user = User::factory()->create([
            'email' => 'ot-'.$suffix.'-'.uniqid().'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);
        $user->outlets()->sync([(int) $outlet->id]);

        return $user;
    }
}
