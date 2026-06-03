<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class KitchenPermissionTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_kitchen_only_user_can_list_and_update_kitchen_tickets(): void
    {
        [$outlet, $ticketId] = $this->seedTicketContext();
        $kitchenUser = $this->createUserWithPermissions(['kitchen.use']);
        Passport::actingAs($kitchenUser);
        $this->assignUserToOutlets($kitchenUser, [(int) $outlet->id]);

        $this->getJson('/api/v1/kitchen/tickets?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $ticketId);

        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', [
            'status' => 'in_progress',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_pos_user_can_still_access_kitchen_apis(): void
    {
        [$outlet, $ticketId] = $this->seedTicketContext();
        $posUser = $this->createUserWithPermissions(['pos.use']);
        Passport::actingAs($posUser);
        $this->assignUserToOutlets($posUser, [(int) $outlet->id]);

        $this->getJson('/api/v1/kitchen/tickets?outletId='.$outlet->id)->assertOk();
        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', [
            'status' => 'in_progress',
        ])->assertOk();
    }

    public function test_user_without_kitchen_or_pos_permission_is_denied(): void
    {
        [$outlet, $ticketId] = $this->seedTicketContext();
        $financeUser = $this->createUserWithPermissions(['finance.reconcile']);
        Passport::actingAs($financeUser);
        $this->assignUserToOutlets($financeUser, [(int) $outlet->id]);

        $this->getJson('/api/v1/kitchen/tickets?outletId='.$outlet->id)->assertForbidden();
        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', [
            'status' => 'in_progress',
        ])->assertForbidden();
    }

    /** @return array{0: Outlet, 1: int} */
    private function seedTicketContext(): array
    {
        $admin = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Kitchen Perm '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'kperm-'.uniqid(),
        ]);
        $this->assignUserToOutlets($admin, [$outlet->id]);

        $orderId = (int) $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'code' => 'KPERM-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'serviceMode' => 'takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '1', 'name' => 'Soup', 'qty' => 1, 'price' => 10000],
            ],
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'payments' => [],
        ])->assertCreated()->json('data.id');

        $ticketId = (int) DB::table('kitchen_tickets')->where('order_id', $orderId)->value('id');

        return [$outlet, $ticketId];
    }

    /** @param list<string> $permissionCodes */
    private function createUserWithPermissions(array $permissionCodes): User
    {
        $this->seedUserManagementGatePermissions();
        $permissionIds = Permission::query()
            ->whereIn('code', $permissionCodes)
            ->pluck('id')
            ->all();

        $role = Role::query()->create([
            'name' => 'kitchen-perm-'.uniqid(),
            'description' => 'Kitchen permission test role',
        ]);
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'email' => 'kitchen-perm-'.uniqid().'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
