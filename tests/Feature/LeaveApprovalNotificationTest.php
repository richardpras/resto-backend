<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeLeaveBalance;
use App\Models\Modules\HR\Domain\LeaveType;
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

class LeaveApprovalNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    public function test_create_notifies_managers_and_approve_notifies_employee_user(): void
    {
        $ctx = $this->seedLeaveActors();

        Passport::actingAs($ctx['requester']);
        $leaveId = (int) $this->postJson('/api/v1/leave-requests', [
            'employeeId' => $ctx['employee']->id,
            'leaveTypeId' => $ctx['leaveType']->id,
            'startDate' => '2026-11-01',
            'endDate' => '2026-11-02',
            'reason' => 'Personal',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $ctx['manager']->id,
            'source_module' => UserNotification::MODULE_HR,
            'source_type' => ApprovalNotificationService::TYPE_LEAVE_REQUEST_PENDING,
            'source_id' => (string) $leaveId,
            'action_url' => '/hr/leave?id='.$leaveId,
        ]);

        Passport::actingAs($ctx['manager']);
        $this->patchJson('/api/v1/leave-requests/'.$leaveId.'/approve')->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $ctx['employeeUser']->id,
            'source_type' => ApprovalNotificationService::TYPE_LEAVE_REQUEST_APPROVED,
            'severity' => UserNotification::SEVERITY_SUCCESS,
        ]);
    }

    public function test_reject_notifies_employee_user(): void
    {
        $ctx = $this->seedLeaveActors();

        Passport::actingAs($ctx['requester']);
        $leaveId = (int) $this->postJson('/api/v1/leave-requests', [
            'employeeId' => $ctx['employee']->id,
            'leaveTypeId' => $ctx['leaveType']->id,
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-01',
        ])->assertCreated()->json('data.id');

        Passport::actingAs($ctx['manager']);
        $this->patchJson('/api/v1/leave-requests/'.$leaveId.'/reject', [
            'rejectionReason' => 'Peak season',
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $ctx['employeeUser']->id,
            'source_type' => ApprovalNotificationService::TYPE_LEAVE_REQUEST_REJECTED,
            'severity' => UserNotification::SEVERITY_WARNING,
        ]);
    }

    /** @return array{employee:Employee,employeeUser:User,requester:User,manager:User,leaveType:LeaveType} */
    private function seedLeaveActors(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Leave Notify Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lv-notify',
        ]);

        $employeeUser = User::factory()->create(['email' => 'emp-leave-'.uniqid().'@test.local']);
        $employeeUser->outlets()->sync([(int) $outlet->id]);

        $employee = Employee::query()->create([
            'user_id' => $employeeUser->id,
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-LN-01',
            'full_name' => 'Leave Employee',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $leaveType = LeaveType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'annual',
            'name' => 'Annual Leave',
            'is_paid' => true,
            'requires_attachment' => false,
            'is_active' => true,
        ]);

        EmployeeLeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'allocated_days' => 12,
            'used_days' => 0,
            'remaining_days' => 12,
        ]);

        $requester = $this->createHrUser($outlet, ['leave.manage'], 'req');
        $manager = $this->createHrUser($outlet, ['leave.manage'], 'mgr');

        return compact('employee', 'employeeUser', 'requester', 'manager', 'leaveType');
    }

    /** @param list<string> $permissions */
    private function createHrUser(Outlet $outlet, array $permissions, string $suffix): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_leave_notify_'.$suffix.'__'],
            ['description' => 'Leave notify'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', $permissions)->pluck('id')->all(),
        );

        $user = User::factory()->create([
            'email' => 'leave-'.$suffix.'-'.uniqid().'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);
        $user->outlets()->sync([(int) $outlet->id]);

        return $user;
    }
}
