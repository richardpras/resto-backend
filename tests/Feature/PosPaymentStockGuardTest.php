<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryIncident;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PosPaymentStockGuardTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->setStockEnforcementMode('strict');
    }

    public function test_PosStock_validation_failure_returns_structured_422(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('PosStock Structured');
        $this->seedFullAccounts((int) $outlet->id);
        [, , $menuId] = $this->seedRecipeContext((int) $outlet->id, 10.0, 1.0, 0, 50);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-STOCK-422', $menuId, qty: 1, unitPrice: 30);

        $response = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('recoverable', true)
            ->assertJsonPath('orderId', $orderId)
            ->assertJsonPath('orderCode', 'POS-STOCK-422')
            ->assertJsonStructure([
                'errors' => ['stock' => [['menuItemId', 'name', 'requested', 'available', 'outletId']]],
            ]);

        $stockRows = $response->json('errors.stock');
        $this->assertIsArray($stockRows);
        $this->assertSame(
            count($stockRows),
            count(array_unique(array_column($stockRows, 'menuItemId'))),
            'Stock errors must be deduped per menu item.',
        );
    }

    public function test_OpenBill_stock_failure_then_retry_does_not_create_duplicate_order(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('PosStock OpenBill Retry');
        $this->seedFullAccounts((int) $outlet->id);
        [, , $menuId] = $this->seedRecipeContext((int) $outlet->id, 10.0, 1.0, 0, 50);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-STOCK-RETRY', $menuId, qty: 1, unitPrice: 30);
        $idem = 'pos-checkout-order-'.$orderId;

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
            'idempotencyKey' => $idem,
        ])->assertStatus(422);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
            'idempotencyKey' => $idem,
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'code' => 'POS-STOCK-RETRY',
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_PosPayment_idempotency_replay_does_not_duplicate_payment(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('PosPayment Idempotency');
        $this->seedFullAccounts((int) $outlet->id);
        [, , $menuId] = $this->seedRecipeContext((int) $outlet->id, 1.0, 1.0, 100, 100);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-IDEM-PAY', $menuId, qty: 1, unitPrice: 30);
        $idem = 'pos-pay-attempt-'.uniqid();

        $first = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
            'idempotencyKey' => $idem,
        ])->assertOk();

        $second = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
            'idempotencyKey' => $idem,
        ])->assertOk();

        $this->assertSame((int) $first->json('data.id'), (int) $second->json('data.id'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_status' => 'paid']);
    }

    public function test_OpenBill_recovery_reuses_order_on_create_idempotency(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('PosOpenBill Recovery');
        $this->seedFullAccounts((int) $outlet->id);
        [, , $menuId] = $this->seedRecipeContext((int) $outlet->id, 1.0, 1.0, 100, 100);
        $session = $this->openPosSession((int) $outlet->id, (int) $user->id);
        $table = $this->createTable((int) $outlet->id);
        $idem = 'pos-create-attempt-'.uniqid();

        $payload = [
            'tenantId' => 1,
            'outletId' => (int) $outlet->id,
            'code' => 'POS-OPEN-RECOVER',
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => $session,
            'tableId' => $table,
            'items' => [
                ['id' => (string) $menuId, 'name' => 'Menu', 'qty' => 1, 'price' => 30],
            ],
            'subtotal' => 30,
            'tax' => 0,
            'total' => 30,
            'payments' => [],
            'idempotencyKey' => $idem,
        ];

        $first = $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/orders', $payload)->assertCreated();

        $this->assertSame((int) $first->json('data.id'), (int) $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_QrOrderStock_failure_recovery_keeps_link_and_unpaid_state(): void
    {
        [$outlet, $table, $menuItem, $requestId, $code] = $this->seedQrLinkedContext();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $this->seedFullAccounts((int) $outlet->id);

        $session = PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $orderId = (int) $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-QR-STOCK',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [[
                'id' => (string) $menuItem->id,
                'name' => $menuItem->name,
                'qty' => 1,
                'price' => 25000,
            ]],
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'payments' => [],
            'tableId' => $table->id,
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'posSessionId' => $session->id,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 25000]],
        ])->assertStatus(422);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_status' => 'unpaid',
            'source_type' => 'qr_order',
            'source_id' => $requestId,
        ]);
        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'order_id' => $orderId,
        ]);
        $this->assertNotSame('paid', DB::table('qr_order_requests')->where('id', $requestId)->value('status'));
    }

    public function test_kds_and_print_not_triggered_on_failed_stock_payment(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('PosStock No KDS');
        $this->seedFullAccounts((int) $outlet->id);
        [, , $menuId] = $this->seedRecipeContext((int) $outlet->id, 10.0, 1.0, 0, 50);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-STOCK-NOKDS', $menuId, qty: 1, unitPrice: 30);

        $ticketCount = DB::table('kitchen_tickets')->where('order_id', $orderId)->count();
        $printCount = DB::table('print_jobs')->where('source_id', (string) $orderId)->count();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertStatus(422);

        $this->assertSame($ticketCount, DB::table('kitchen_tickets')->where('order_id', $orderId)->count());
        $this->assertSame($printCount, DB::table('print_jobs')->where('source_id', (string) $orderId)->count());
        $this->assertDatabaseMissing('payments', ['order_id' => $orderId]);
    }

    public function test_PosStock_warning_mode_allows_sale_and_records_incident(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('PosStock Setting Off');
        $this->setStockEnforcementMode('warning');
        $this->seedFullAccounts((int) $outlet->id);
        [, , $menuId] = $this->seedRecipeContext((int) $outlet->id, 10.0, 1.0, 0, 50);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-STOCK-OFF', $menuId, qty: 1, unitPrice: 30);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $this->assertTrue(
            InventoryIncident::query()
                ->where('order_id', $orderId)
                ->where('incident_type', InventoryIncident::TYPE_INSUFFICIENT_ON_SALE)
                ->exists()
        );
    }

    public function test_failed_payment_does_not_create_duplicate_order_on_paid_create_retry(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('PosStock No Dup Order');
        $this->seedFullAccounts((int) $outlet->id);
        [, , $menuId] = $this->seedRecipeContext((int) $outlet->id, 5.0, 1.0, 0, 50);
        $session = $this->openPosSession((int) $outlet->id, (int) $user->id);
        $table = $this->createTable((int) $outlet->id);

        $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => (int) $outlet->id,
            'code' => 'POS-CREATE-DUP',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'paid',
            'serviceMode' => 'takeaway',
            'orderChannel' => 'pos',
            'posSessionId' => $session,
            'tableId' => $table,
            'items' => [
                ['id' => (string) $menuId, 'name' => 'Menu', 'qty' => 1, 'price' => 30],
            ],
            'subtotal' => 30,
            'tax' => 0,
            'total' => 30,
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('orders', ['code' => 'POS-CREATE-DUP']);
        $this->assertDatabaseCount('orders', 0);
    }

    /** @return array{0:User,1:Outlet} */
    private function actAsAdminWithOutlet(string $suffix): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => $suffix,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower(str_replace(' ', '-', $suffix)).'-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$user, $outlet];
    }

    /** @return array{0:Ingredient,1:Ingredient,2:int} */
    private function seedRecipeContext(
        int $outletId,
        float $recipeQtyA,
        float $recipeQtyB,
        float $stockA,
        float $stockB,
    ): array {
        $ingA = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Ing A '.uniqid(),
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 2.0,
        ]);
        $ingB = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Ing B '.uniqid(),
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 1.0,
        ]);
        DB::table('inventory_stocks')->insert([
            ['ingredient_id' => (int) $ingA->id, 'outlet_id' => $outletId, 'stock' => $stockA, 'created_at' => now(), 'updated_at' => now()],
            ['ingredient_id' => (int) $ingB->id, 'outlet_id' => $outletId, 'stock' => $stockB, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $menuId = (int) DB::table('menu_items')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Nasi Goreng',
            'price' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('menu_recipes')->insert([
            ['menu_item_id' => $menuId, 'inventory_item_id' => (int) $ingA->id, 'quantity' => $recipeQtyA, 'created_at' => now(), 'updated_at' => now()],
            ['menu_item_id' => $menuId, 'inventory_item_id' => (int) $ingB->id, 'quantity' => $recipeQtyB, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$ingA, $ingB, $menuId];
    }

    private function createConfirmedOrder(int $outletId, int $userId, string $code, int $menuItemId, int $qty, float $unitPrice): int
    {
        $table = $this->createTable($outletId);
        $session = $this->openPosSession($outletId, $userId);
        $total = $qty * $unitPrice;

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
                ['id' => (string) $menuItemId, 'name' => 'Menu', 'qty' => $qty, 'price' => $unitPrice],
            ],
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'payments' => [],
        ]);
        $resp->assertCreated();

        return (int) $resp->json('data.id');
    }

    private function openPosSession(int $outletId, int $userId): int
    {
        return (int) PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $userId,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ])->id;
    }

    private function createTable(int $outletId): int
    {
        return (int) RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ])->id;
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: int, 4: string} */
    private function seedQrLinkedContext(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'QR Stock Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'qr-stock-'.uniqid(),
        ]);
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'QR-1',
            'capacity' => 4,
            'status' => 'active',
        ]);
        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Nasi Goreng',
            'category' => 'main',
            'price' => 25000,
            'available' => true,
        ]);
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Rice '.uniqid(),
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 1.0,
        ]);
        DB::table('inventory_stocks')->insert([
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => (int) $outlet->id,
            'stock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('menu_recipes')->insert([
            'menu_item_id' => $menuItem->id,
            'inventory_item_id' => (int) $ingredient->id,
            'quantity' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/qr-orders', [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
            'customerName' => 'Guest',
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
        ])->assertCreated();

        return [$outlet, $table, $menuItem, (int) $create->json('data.id'), (string) $create->json('data.requestCode')];
    }

    private function setStockEnforcementMode(string $mode): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['id' => 1],
            [
                'enable_split_bill' => true,
                'enable_multi_payment' => true,
                'confirm_before_payment' => true,
                'enable_qr_ordering' => true,
                'enable_call_cashier' => true,
                'stock_enforcement_mode' => $mode,
                'enforce_stock_on_sale' => $mode === 'strict',
                'employee_self_service_enabled' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function seedFullAccounts(int $outletId): void
    {
        Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'cash_bank',
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);
        Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'sales_revenue',
            'code' => '4100',
            'name' => 'Sales',
            'type' => 'revenue',
            'subtype' => 'revenue',
            'is_active' => true,
        ]);
        Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'cogs',
            'code' => '5100',
            'name' => 'COGS',
            'type' => 'expense',
            'subtype' => 'cogs',
            'is_active' => true,
        ]);
        Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'inventory',
            'code' => '1300',
            'name' => 'Inventory',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);
    }
}
