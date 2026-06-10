<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Notifications\Services\ApprovalNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseOrderApprovalNotificationTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    /** @return array{poId:int,submitter:\App\Models\User,approver:\App\Models\User,outlet:Outlet} */
    private function seedSubmittedPo(): array
    {
        $outlet = $this->createOutlet();
        $submitter = $this->createProcurementUser($outlet, 'po-sub');
        $approver = $this->createProcurementUser($outlet, 'po-apr');
        $master = $this->seedProcurementMasterData((int) $outlet->id, 'po-n');

        Passport::actingAs($submitter);
        $poId = (int) DB::table('purchase_orders')->insertGetId([
            'outlet_id' => $outlet->id,
            'purchase_request_id' => $master['prId'],
            'supplier_id' => $master['supplierId'],
            'number' => 'PO-NOTIFY-'.uniqid(),
            'status' => 'draft',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $poId,
            'ingredient_id' => $master['ingredientId'],
            'ordered_qty' => 5,
            'received_qty' => 0,
            'unit_price' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->patchJson("/api/v1/purchase-orders/{$poId}/submit")->assertOk();

        return ['poId' => $poId, 'submitter' => $submitter, 'approver' => $approver, 'outlet' => $outlet];
    }

    public function test_submit_notifies_approvers(): void
    {
        $ctx = $this->seedSubmittedPo();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $ctx['approver']->id,
            'source_type' => ApprovalNotificationService::TYPE_PURCHASE_ORDER_PENDING,
            'source_id' => (string) $ctx['poId'],
            'action_url' => '/purchases?tab=orders&id='.$ctx['poId'],
        ]);
    }

    public function test_approve_notifies_submitter(): void
    {
        $ctx = $this->seedSubmittedPo();
        Passport::actingAs($ctx['approver']);
        $this->patchJson("/api/v1/purchase-orders/{$ctx['poId']}/approve")->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $ctx['submitter']->id,
            'source_type' => ApprovalNotificationService::TYPE_PURCHASE_ORDER_APPROVED,
            'severity' => UserNotification::SEVERITY_SUCCESS,
        ]);
    }

    public function test_reject_notifies_submitter(): void
    {
        $ctx = $this->seedSubmittedPo();
        Passport::actingAs($ctx['approver']);
        $this->patchJson("/api/v1/purchase-orders/{$ctx['poId']}/reject")->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $ctx['submitter']->id,
            'source_type' => ApprovalNotificationService::TYPE_PURCHASE_ORDER_REJECTED,
            'severity' => UserNotification::SEVERITY_WARNING,
        ]);
    }

    private function createProcurementUser(Outlet $outlet, string $suffix): \App\Models\User
    {
        $this->seedProcurementPermissions();
        $user = User::factory()->create([
            'email' => 'po-notify-'.$suffix.'-'.uniqid().'@test.local',
            'password' => 'secret123',
        ]);
        $role = \App\Models\Modules\UserManagement\Domain\Role::query()->firstOrCreate(
            ['name' => '__test_po_notify_'.$suffix.'__'],
            ['description' => 'PO notify'],
        );
        $role->permissions()->sync(
            \App\Models\Modules\UserManagement\Domain\Permission::query()
                ->whereIn('code', ['purchase.manage', 'outlets.view_all'])
                ->pluck('id')
                ->all(),
        );
        $user->roles()->sync([$role->id]);
        $user->outlets()->sync([(int) $outlet->id]);

        return $user;
    }
}
