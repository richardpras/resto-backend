<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase11EndToEndOperationalFlowTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['payments.providers.manual.webhook_secret' => 'phase11-secret']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_dine_in_operational_lifecycle_reaches_served_paid_accounted_and_deducted(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11 E2E Dine In');
        [$ingredient, $menuItem] = $this->seedRecipeForMenu((int) $outlet->id, 120.0, 3.0);
        $this->seedCoreAccounts((int) $outlet->id, true);
        $table = $this->createTable((int) $outlet->id, 'P11-DINE-T1');
        $session = $this->openSession((int) $outlet->id, (int) $user->id);

        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => (int) $outlet->id,
            'code' => 'P11-DINE-1',
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => (int) $session->id,
            'tableId' => (int) $table->id,
            'items' => [
                ['id' => (string) $menuItem->id, 'name' => 'P11 Bowl', 'qty' => 2, 'price' => 45],
            ],
            'subtotal' => 90,
            'tax' => 0,
            'total' => 90,
            'payments' => [],
        ]);
        $create->assertCreated();
        $orderId = (int) $create->json('data.id');

        $this->assertDatabaseHas('kitchen_tickets', ['order_id' => $orderId, 'status' => 'queued']);
        $this->assertDatabaseHas('print_jobs', ['source_type' => 'order', 'source_id' => $orderId, 'status' => 'pending']);

        $orderItemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');
        $this->postJson("/api/v1/orders/{$orderId}/splits", [
            'splitType' => 'by_item',
            'label' => 'Guest A',
            'items' => [[
                'orderItemId' => $orderItemId,
                'qty' => 1,
                'amount' => 45,
            ]],
        ])->assertCreated();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'partial');

        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => (int) $outlet->id,
            'stock' => 120.0,
        ]);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'qris', 'amount' => 60]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $ticketId = (int) DB::table('kitchen_tickets')->where('order_id', $orderId)->value('id');
        $this->patchJson("/api/v1/kitchen/tickets/{$ticketId}/status", ['status' => 'in_progress'])->assertOk();
        $this->patchJson("/api/v1/kitchen/tickets/{$ticketId}/status", ['status' => 'ready'])->assertOk();
        $this->patchJson("/api/v1/kitchen/tickets/{$ticketId}/status", ['status' => 'served'])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_status' => 'paid',
            'kitchen_status' => 'served',
        ]);
        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => (int) $outlet->id,
            'stock' => 114.0,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => (int) $ingredient->id,
            'source_type' => 'order_payment',
            'source_id' => 'P11-DINE-1',
        ]);
        $this->assertDatabaseHas('journals', [
            'source_type' => 'order_payment',
            'source_id' => (string) $orderId,
            'status' => 'posted',
        ]);
    }

    public function test_qr_operational_lifecycle_covers_request_confirm_and_gateway_webhook(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11 E2E QR');
        $this->seedCoreAccounts((int) $outlet->id, false);
        $table = $this->createTable((int) $outlet->id, 'P11-QR-T1');
        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'name' => 'P11 QR Menu',
            'category' => 'main',
            'price' => 25000,
            'available' => true,
        ]);
        $this->openSession((int) $outlet->id, (int) $user->id);

        $createQr = $this->postJson('/api/v1/qr-orders', [
            'outletId' => (int) $outlet->id,
            'tableId' => (int) $table->id,
            'customerName' => 'Guest QR',
            'items' => [['menuItemId' => (int) $menuItem->id, 'qty' => 1]],
        ]);
        $createQr->assertCreated();
        $requestId = (int) $createQr->json('data.id');

        $confirm = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm");
        $confirm->assertOk();
        $orderId = (int) $confirm->json('data.orderId');

        $this->assertDatabaseHas('kitchen_tickets', ['order_id' => $orderId, 'status' => 'queued']);

        $tx = $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'outletId' => (int) $outlet->id,
            'provider' => 'manual',
            'externalReference' => 'p11-qr-webhook-1',
            'idempotencyKey' => 'p11-qr-webhook-1',
            'amount' => 25000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
        ]);
        $tx->assertCreated();
        $transactionId = (int) $tx->json('data.id');

        $payload = [
            'externalReference' => 'p11-qr-webhook-1',
            'status' => 'paid',
            'eventId' => 'p11-evt-1',
            'paymentMethod' => 'qris',
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $signature = hash_hmac('sha256', $raw, (string) config('payments.providers.manual.webhook_secret'));
        $this->withHeaders(['X-Signature' => $signature])
            ->postJson('/api/v1/payment-webhooks/manual', $payload)
            ->assertOk();

        $this->assertDatabaseHas('payment_transactions', ['id' => $transactionId, 'status' => 'paid']);
        $this->assertDatabaseHas('journals', [
            'source_type' => 'payment_transaction',
            'source_id' => (string) $transactionId,
            'status' => 'posted',
        ]);
    }

    public function test_takeaway_checkout_shift_reconcile_and_pos_session_close_posting_flow(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11 E2E Takeaway');
        $this->seedCoreAccounts((int) $outlet->id, true);

        $open = $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => (int) $outlet->id,
            'openingCash' => 100000,
            'notes' => 'P11 Open Shift',
        ]);
        $open->assertCreated();
        $sessionId = (int) $open->json('data.id');

        $order = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => (int) $outlet->id,
            'code' => 'P11-TAKE-1',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'takeaway',
            'items' => [['id' => '9001', 'name' => 'Takeaway Item', 'qty' => 1, 'price' => 50000]],
            'subtotal' => 50000,
            'tax' => 0,
            'total' => 50000,
            'payments' => [],
        ]);
        $order->assertCreated();
        $orderId = (int) $order->json('data.id');

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $closeShift = $this->postJson('/api/v1/orders/shift-close', [
            'tenantId' => 1,
            'outletId' => (int) $outlet->id,
        ]);
        $closeShift->assertOk()
            ->assertJsonPath('data.orderCount', 0)
            ->assertJsonPath('data.skipped', true);

        $closeSession = $this->postJson("/api/v1/pos-sessions/{$sessionId}/close", [
            'closingCash' => 149000,
            'notes' => 'P11 Close Shift',
        ]);
        $closeSession->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.cashVariance', 49000);

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'is_posted' => true]);
        $this->assertDatabaseHas('journals', ['source_type' => 'order_payment', 'outlet_id' => (int) $outlet->id]);
        $this->assertDatabaseMissing('journals', ['source_type' => 'shift_close', 'outlet_id' => (int) $outlet->id]);
        $this->assertDatabaseHas('journals', ['source_type' => 'pos_cash_variance', 'source_id' => (string) $sessionId]);
    }

    /** @return array{0:User,1:Outlet} */
    private function actAsAdminWithOutlet(string $name): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p11-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$user, $outlet];
    }

    private function createTable(int $outletId, string $name): RestaurantTable
    {
        return RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => $name,
            'capacity' => 4,
            'status' => 'active',
        ]);
    }

    private function openSession(int $outletId, int $userId): PosSession
    {
        return PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $userId,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);
    }

    /** @return array{0:Ingredient,1:MenuItem} */
    private function seedRecipeForMenu(int $outletId, float $initialStock, float $recipeQty): array
    {
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'P11 Ingredient '.uniqid(),
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 2,
        ]);
        DB::table('inventory_stocks')->insert([
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => $outletId,
            'stock' => $initialStock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'P11 Bowl',
            'category' => 'main',
            'price' => 45,
            'available' => true,
        ]);
        DB::table('menu_recipes')->insert([
            'menu_item_id' => (int) $menuItem->id,
            'inventory_item_id' => (int) $ingredient->id,
            'quantity' => $recipeQty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$ingredient, $menuItem];
    }

    private function seedCoreAccounts(int $outletId, bool $withVarianceAccount): void
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

        if ($withVarianceAccount) {
            Account::query()->create([
                'tenant_id' => 1,
                'outlet_id' => $outletId,
                'scope' => 'outlet',
                'category' => 'cash_variance',
                'code' => '5400',
                'name' => 'Cash Over Short',
                'type' => 'expense',
                'subtype' => 'operational_expense',
                'is_active' => true,
            ]);
        }
    }
}
