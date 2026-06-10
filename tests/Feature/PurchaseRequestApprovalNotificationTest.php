<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\Notifications\Services\ApprovalNotificationService;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseRequestApprovalNotificationTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_submit_notifies_approvers_and_approve_notifies_requester(): void
    {
        $outlet = $this->createOutlet();
        $submitter = $this->createProcurementUser($outlet, 'submitter');
        $approver = $this->createProcurementUser($outlet, 'approver');

        Passport::actingAs($submitter);
        $ingredientId = $this->seedIngredient($outlet->id);
        $prId = (int) $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'items' => [['inventoryItemId' => $ingredientId, 'quantity' => 2, 'unit' => 'kg']],
        ])->json('data.id');

        $this->postJson("/api/v1/purchase-requests/{$prId}/submit")->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $approver->id,
            'outlet_id' => $outlet->id,
            'source_module' => UserNotification::MODULE_PROCUREMENT,
            'source_type' => ApprovalNotificationService::TYPE_PURCHASE_REQUEST_PENDING,
            'source_id' => (string) $prId,
            'severity' => UserNotification::SEVERITY_INFO,
            'action_url' => '/purchases?tab=requests&id='.$prId,
        ]);

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $submitter->id,
            'source_type' => ApprovalNotificationService::TYPE_PURCHASE_REQUEST_PENDING,
            'source_id' => (string) $prId,
        ]);

        Passport::actingAs($approver);
        $this->postJson("/api/v1/purchase-requests/{$prId}/approve")->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $submitter->id,
            'source_type' => ApprovalNotificationService::TYPE_PURCHASE_REQUEST_APPROVED,
            'source_id' => (string) $prId,
            'severity' => UserNotification::SEVERITY_SUCCESS,
        ]);
    }

    public function test_reject_notifies_requester_with_warning(): void
    {
        $outlet = $this->createOutlet();
        $submitter = $this->createProcurementUser($outlet, 'submitter-r');
        $approver = $this->createProcurementUser($outlet, 'approver-r');

        Passport::actingAs($submitter);
        $ingredientId = $this->seedIngredient($outlet->id);
        $prId = (int) $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'items' => [['inventoryItemId' => $ingredientId, 'quantity' => 1, 'unit' => 'kg']],
        ])->json('data.id');
        $this->postJson("/api/v1/purchase-requests/{$prId}/submit")->assertOk();

        Passport::actingAs($approver);
        $this->postJson("/api/v1/purchase-requests/{$prId}/reject")->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $submitter->id,
            'source_type' => ApprovalNotificationService::TYPE_PURCHASE_REQUEST_REJECTED,
            'severity' => UserNotification::SEVERITY_WARNING,
        ]);
    }

    private function createProcurementUser(Outlet $outlet, string $suffix): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_pr_notify_'.$suffix.'__'],
            ['description' => 'Procurement notify test'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['purchase.manage', 'outlets.view_all'])->pluck('id')->all(),
        );

        $user = User::factory()->create([
            'email' => 'pr-notify-'.$suffix.'-'.uniqid().'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);
        $user->outlets()->sync([(int) $outlet->id]);

        return $user;
    }

    private function seedIngredient(int $outletId): int
    {
        return (int) DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Notify Ingredient',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 5,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
