<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PosPaymentIdempotencyGuardTest extends TestCase
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

    public function test_QrOrder_payment_retry_after_stock_failure_does_not_create_duplicate_order(): void
    {
        [$outlet, $table, $menuItem, $requestId] = $this->seedQrContext();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $this->seedFullAccounts((int) $outlet->id);

        $session = $this->openPosSession((int) $outlet->id, (int) $user->id);
        $idem = 'pos-qr-checkout-'.$requestId;
        $itemPayload = [[
            'id' => (string) $menuItem->id,
            'name' => $menuItem->name,
            'qty' => 1,
            'price' => 25000,
        ]];

        $first = $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-QR-IDEM-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => $itemPayload,
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'payments' => [],
            'tableId' => $table->id,
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'posSessionId' => $session,
            'qrOrderRequestId' => $requestId,
            'idempotencyKey' => $idem,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated();

        $orderId = (int) $first->json('data.id');

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 25000]],
        ])->assertStatus(422);

        $second = $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-QR-IDEM-2',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => $itemPayload,
            'subtotal' => 25000,
            'tax' => 0,
            'total' => 25000,
            'payments' => [],
            'tableId' => $table->id,
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'posSessionId' => $session,
            'qrOrderRequestId' => $requestId,
            'idempotencyKey' => 'pos-checkout-order-'.$orderId,
            'confirmedAt' => now()->toISOString(),
        ])->assertOk()
            ->assertJsonPath('meta.action', 'resume_existing_order')
            ->assertJsonPath('meta.existingOrderId', $orderId);

        $this->assertSame($orderId, (int) $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'order_id' => $orderId,
        ]);
    }

    public function test_QrOrder_reopen_resumes_linked_open_bill_without_new_order(): void
    {
        [$outlet, $table, $menuItem, $requestId] = $this->seedQrContext();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $this->seedFullAccounts((int) $outlet->id);

        $session = $this->openPosSession((int) $outlet->id, (int) $user->id);
        $orderId = (int) $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-QR-REOPEN',
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
            'posSessionId' => $session,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertCreated()->json('data.id');

        $resume = $this->postJson('/api/v1/orders', [
            'outletId' => $outlet->id,
            'code' => 'POS-QR-REOPEN-2',
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
            'posSessionId' => $session,
            'qrOrderRequestId' => $requestId,
            'confirmedAt' => now()->toISOString(),
        ])->assertOk()
            ->assertJsonPath('meta.action', 'resume_existing_order');

        $this->assertSame($orderId, (int) $resume->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertTrue(
            PosEventLog::query()
                ->where('event_type', 'qr_order.resume_existing_bill')
                ->where('entity_id', $orderId)
                ->exists()
        );
    }

    public function test_double_click_payment_replays_single_payment_request(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('PosPayment Double Click');
        $this->seedFullAccounts((int) $outlet->id);
        [, , $menuId] = $this->seedRecipeContext((int) $outlet->id, 1.0, 1.0, 100, 100);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-DBL-CLK', $menuId, qty: 1, unitPrice: 30);
        $idem = 'pos-pay-double-'.uniqid();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
            'idempotencyKey' => $idem,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
            'idempotencyKey' => $idem,
        ])->assertOk();

        $this->assertDatabaseCount('payments', 1);
        $this->assertTrue(
            PosEventLog::query()
                ->where('event_type', 'payment.idempotency_hit')
                ->where('entity_id', $orderId)
                ->exists()
        );
        $this->assertTrue(
            PosEventLog::query()
                ->where('event_type', 'payment.retry_detected')
                ->where('entity_id', $orderId)
                ->exists()
        );
    }

    public function test_checkout_integrity_health_endpoint_returns_metrics(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('PosIntegrity Health');
        $this->seedFullAccounts((int) $outlet->id);
        [, , $menuId] = $this->seedRecipeContext((int) $outlet->id, 1.0, 1.0, 100, 100);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-HEALTH', $menuId, qty: 1, unitPrice: 30);
        $idem = 'pos-health-'.uniqid();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
            'idempotencyKey' => $idem,
        ])->assertOk();
        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
            'idempotencyKey' => $idem,
        ])->assertOk();

        $this->getJson('/api/v1/pos/checkout-integrity-health?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.label', 'Duplicate Order Prevention')
            ->assertJsonStructure([
                'data' => [
                    'retries',
                    'idempotencyHits',
                    'duplicatePreventionCount',
                    'resumeExistingOrderCount',
                    'qrResumeCount',
                ],
            ]);
    }

    /**
     * @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: int}
     */
    private function seedQrContext(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'QR Idem Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'qr-idem-'.uniqid(),
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

        return [$outlet, $table, $menuItem, (int) $create->json('data.id')];
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
        foreach ([
            ['1100', 'Cash', 'asset', 'current_asset', 'cash_bank'],
            ['4100', 'Sales', 'revenue', 'revenue', 'sales_revenue'],
            ['5100', 'COGS', 'expense', 'cogs', 'cogs'],
            ['1300', 'Inventory', 'asset', 'current_asset', 'inventory'],
        ] as [$code, $name, $type, $subtype, $category]) {
            Account::query()->create([
                'tenant_id' => 1,
                'outlet_id' => $outletId,
                'scope' => 'outlet',
                'category' => $category,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'subtype' => $subtype,
                'is_active' => true,
            ]);
        }
    }

    /** @return array{0: User, 1: Outlet} */
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

    /** @return array{0: Ingredient, 1: Ingredient, 2: int} */
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
}
