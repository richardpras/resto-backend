<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AuthorityHardening01Test extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_guest_cannot_access_financial_or_allocation_mutation_endpoints(): void
    {
        [$outlet, $table, $order] = $this->seedOutletTableOrder();

        $this->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ])->assertStatus(401);

        $this->postJson('/api/v1/orders/'.$order->id.'/splits', [
            'splitType' => 'equal',
            'label' => 'Split 1',
            'items' => [],
        ])->assertStatus(401);

        $this->postJson('/api/v1/orders/shift-close', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
        ])->assertStatus(401);

        $this->postJson('/api/v1/payment-transactions/reconcile', [
            'provider' => 'xendit',
            'externalReference' => 'test-ref',
        ])->assertStatus(401);

        $this->postJson('/api/v1/gift-cards/settlements', [
            'redemptionIds' => [1],
        ])->assertStatus(401);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'unpaid',
            'status' => 'confirmed',
        ]);
    }

    public function test_staff_can_mutate_payments_and_qr_customer_public_flow_remains_limited(): void
    {
        [$outlet, $table, $order] = $this->seedOutletTableOrder();
        $menu = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Menu-'.uniqid(),
            'category' => 'main',
            'price' => 10000,
            'available' => true,
        ]);

        // Public QR customer flow stays operational: submit + call cashier only.
        $request = $this->postJson('/api/v1/qr-orders', [
            'outletId' => (int) $outlet->id,
            'tableId' => (int) $table->id,
            'customerName' => 'Guest',
            'items' => [
                ['menuItemId' => (int) $menu->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $requestId = (int) $request->json('data.id');
        $this->postJson('/api/v1/qr-orders/'.$requestId.'/call-cashier', [
            'outletId' => (int) $outlet->id,
            'tableId' => (int) $table->id,
        ])->assertOk()->assertJsonPath('data.cashierCallCount', 1);

        $staff = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($staff, [(int) $outlet->id]);

        // Staff authority preserved: can record payment and settle open bill context.
        $this->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'payments' => [['method' => 'cash', 'amount' => 12000, 'paidAt' => now()->toISOString()],
            ],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)
            ->assertOk();

        // Regression guards: waiter-call and kitchen flow still intact.
        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'cashier_call_count' => 1,
            'status' => 'pending_cashier_confirmation',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'paid',
        ]);
    }

    public function test_reconcile_and_shift_close_require_finance_scoped_permissions(): void
    {
        [$outlet] = $this->seedOutletTableOrder();

        $staffUser = $this->createUserWithPermissions(['pos.use']);
        Passport::actingAs($staffUser);
        $this->assignUserToOutlets($staffUser, [(int) $outlet->id]);

        $this->postJson('/api/v1/payment-transactions/reconcile', [])->assertStatus(403);
        $this->postJson('/api/v1/orders/shift-close', [
            'tenantId' => 1,
            'outletId' => (int) $outlet->id,
        ])->assertStatus(403);

        $financeUser = $this->createUserWithPermissions(['finance.reconcile', 'finance.shift_close']);
        Passport::actingAs($financeUser);
        $this->assignUserToOutlets($financeUser, [(int) $outlet->id]);

        $reconcileResponse = $this->postJson('/api/v1/payment-transactions/reconcile', []);
        self::assertNotSame(403, $reconcileResponse->status());

        $shiftCloseResponse = $this->postJson('/api/v1/orders/shift-close', [
            'tenantId' => 1,
            'outletId' => (int) $outlet->id,
        ]);
        self::assertNotSame(403, $shiftCloseResponse->status());

        $admin = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $this->postJson('/api/v1/payment-transactions/reconcile', [])->assertStatus(200);
    }

    private function seedOutletTableOrder(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Authority Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'auth-'.uniqid(),
        ]);
        $table = RestaurantTable::query()->create([
            'outlet_id' => (int) $outlet->id,
            'name' => 'T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'ORD-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine-in',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'subtotal' => 10000,
            'tax' => 2000,
            'total' => 12000,
            'paid_total' => 0,
            'balance_due' => 12000,
            'table_id' => (int) $table->id,
            'table_name' => (string) $table->name,
        ]);

        return [$outlet, $table, $order];
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
            'name' => 'auth-hardening-role-'.uniqid(),
            'description' => 'Authority hardening scoped role',
        ]);
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'email' => 'authority-hardening-'.uniqid().'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
