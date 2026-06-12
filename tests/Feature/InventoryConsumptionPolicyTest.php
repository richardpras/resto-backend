<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryConsumptionQueue;
use App\Models\Modules\Inventory\Domain\InventoryIncident;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class InventoryConsumptionPolicyTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_deferred_mode_allows_payment_and_queues_consumption(): void
    {
        [$user, $outlet, $menuId] = $this->seedContext(stock: 0, mode: 'deferred');
        $this->seedFullAccounts((int) $outlet->id);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-DEFERRED', $menuId);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $this->assertDatabaseHas('inventory_consumption_queue', [
            'order_id' => $orderId,
            'status' => InventoryConsumptionQueue::STATUS_PENDING,
        ]);
        $this->assertNull(Order::query()->find($orderId)?->stock_deducted_at);
    }

    public function test_warning_mode_allows_payment_with_shortage_incident(): void
    {
        [$user, $outlet, $menuId] = $this->seedContext(stock: 0, mode: 'warning');
        $this->seedFullAccounts((int) $outlet->id);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-WARNING', $menuId);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $this->assertNotNull(Order::query()->find($orderId)?->stock_deducted_at);
        $this->assertTrue(
            InventoryIncident::query()
                ->where('order_id', $orderId)
                ->where('incident_type', InventoryIncident::TYPE_INSUFFICIENT_ON_SALE)
                ->exists()
        );
    }

    public function test_strict_mode_blocks_payment_on_insufficient_stock(): void
    {
        [$user, $outlet, $menuId] = $this->seedContext(stock: 0, mode: 'strict');
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-STRICT', $menuId);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    }

    public function test_manual_posting_processes_deferred_queue(): void
    {
        [$user, $outlet, $menuId] = $this->seedContext(stock: 100, mode: 'deferred');
        $this->seedFullAccounts((int) $outlet->id);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-POST', $menuId);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        $this->postJson('/api/v1/inventory/consumption/post', [
            'outletId' => (int) $outlet->id,
        ])->assertOk()->assertJsonPath('data.processed', 1);

        $this->assertDatabaseHas('inventory_consumption_queue', [
            'order_id' => $orderId,
            'status' => InventoryConsumptionQueue::STATUS_PROCESSED,
        ]);
        $this->assertNotNull(Order::query()->find($orderId)?->stock_deducted_at);
    }

    public function test_shift_close_processes_deferred_consumption_queue(): void
    {
        [$user, $outlet, $menuId] = $this->seedContext(stock: 100, mode: 'deferred');
        $this->seedFullAccounts((int) $outlet->id);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-SHIFT-DEF', $menuId);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        $this->assertDatabaseHas('inventory_consumption_queue', [
            'order_id' => $orderId,
            'status' => InventoryConsumptionQueue::STATUS_PENDING,
        ]);

        $this->postJson('/api/v1/orders/shift-close', [
            'outletId' => (int) $outlet->id,
        ])->assertOk();

        $this->assertDatabaseHas('inventory_consumption_queue', [
            'order_id' => $orderId,
            'status' => InventoryConsumptionQueue::STATUS_PROCESSED,
        ]);
        $this->assertNotNull(Order::query()->find($orderId)?->stock_deducted_at);
    }

    public function test_deferred_posting_creates_cogs_journal(): void
    {
        [$user, $outlet, $menuId] = $this->seedContext(stock: 100, mode: 'deferred');
        $this->seedFullAccounts((int) $outlet->id);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-COGS', $menuId);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        $this->postJson('/api/v1/inventory/consumption/post', [
            'outletId' => (int) $outlet->id,
        ])->assertOk();

        $this->assertDatabaseHas('journals', [
            'source_type' => 'inventory_consumption_posting',
            'outlet_id' => (int) $outlet->id,
        ]);
    }

    public function test_deferred_posting_with_shortage_marks_review_required(): void
    {
        [$user, $outlet, $menuId] = $this->seedContext(stock: 0, mode: 'deferred');
        $this->seedFullAccounts((int) $outlet->id);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-VAR', $menuId);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        $this->postJson('/api/v1/inventory/consumption/post', [
            'outletId' => (int) $outlet->id,
        ])->assertOk();

        $this->assertDatabaseHas('inventory_consumption_queue', [
            'order_id' => $orderId,
            'status' => InventoryConsumptionQueue::STATUS_REVIEW_REQUIRED,
        ]);
        $this->assertTrue(
            InventoryIncident::query()
                ->where('order_id', $orderId)
                ->where('incident_type', InventoryIncident::TYPE_INSUFFICIENT_ON_POSTING)
                ->exists()
        );
    }

    public function test_posting_health_reports_pending_counts(): void
    {
        [$user, $outlet, $menuId] = $this->seedContext(stock: 0, mode: 'deferred');
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-HEALTH', $menuId);
        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        $this->getJson('/api/v1/inventory/posting-health?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.pendingPostings', 1)
            ->assertJsonPath('data.enforcementMode', 'deferred');
    }

    /** @return array{0:\App\Models\User,1:Outlet,2:int} */
    private function seedContext(float $stock, string $mode): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Policy Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'policy-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $this->setStockEnforcementMode($mode);

        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Ing '.uniqid(),
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 2.0,
        ]);
        DB::table('inventory_stocks')->insert([
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => (int) $outlet->id,
            'stock' => $stock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $menuId = (int) DB::table('menu_items')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Menu',
            'price' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('menu_recipes')->insert([
            'menu_item_id' => $menuId,
            'inventory_item_id' => (int) $ingredient->id,
            'quantity' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $outlet, $menuId];
    }

    private function setStockEnforcementMode(string $mode): void
    {
        SystemSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'enable_split_bill' => true,
                'enable_multi_payment' => true,
                'confirm_before_payment' => true,
                'enable_qr_ordering' => true,
                'enable_call_cashier' => true,
                'stock_enforcement_mode' => $mode,
                'enforce_stock_on_sale' => $mode === 'strict',
            ],
        );
    }

    private function createConfirmedOrder(int $outletId, int $userId, string $code, int $menuItemId): int
    {
        $table = (int) RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ])->id;
        $session = (int) PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $userId,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ])->id;

        $resp = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => $session,
            'tableId' => $table,
            'items' => [
                ['id' => (string) $menuItemId, 'name' => 'Menu', 'qty' => 1, 'price' => 30],
            ],
            'subtotal' => 30,
            'tax' => 0,
            'total' => 30,
            'payments' => [],
        ]);
        $resp->assertCreated();

        return (int) $resp->json('data.id');
    }

    private function seedFullAccounts(int $outletId): void
    {
        foreach ([
            ['category' => 'cash_bank', 'code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['category' => 'sales_revenue', 'code' => '4100', 'name' => 'Sales', 'type' => 'revenue', 'subtype' => 'revenue'],
            ['category' => 'cogs', 'code' => '5100', 'name' => 'COGS', 'type' => 'expense', 'subtype' => 'cogs'],
            ['category' => 'inventory', 'code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset'],
        ] as $row) {
            Account::query()->create([
                'tenant_id' => 1,
                'outlet_id' => $outletId,
                'scope' => 'outlet',
                ...$row,
                'is_active' => true,
            ]);
        }
    }
}
