<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Inventory\Domain\DailyStocktakeLine;
use App\Models\Modules\Inventory\Domain\DailyStocktakeSession;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryConsumptionQueue;
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

class DailyStocktakeTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_preview_computes_overnight_and_operational_variance(): void
    {
        [$user, $outlet, $ingredientId] = $this->seedStocktakeContext();
        $businessDate = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $previous = DailyStocktakeSession::query()->create([
            'outlet_id' => $outlet->id,
            'business_date' => $yesterday,
            'status' => DailyStocktakeSession::STATUS_POSTED,
            'posted_at' => now()->subDay(),
        ]);
        DailyStocktakeLine::query()->create([
            'session_id' => $previous->id,
            'ingredient_id' => $ingredientId,
            'previous_closing_qty' => 0,
            'closing_qty' => 100,
        ]);

        $create = $this->postJson('/api/v1/inventory/daily-stocktake', [
            'outletId' => (int) $outlet->id,
            'businessDate' => $businessDate,
        ])->assertCreated();

        $sessionId = (int) $create->json('data.id');

        $this->patchJson("/api/v1/inventory/daily-stocktake/{$sessionId}/opening", [
            'lines' => [['ingredientId' => $ingredientId, 'openingQty' => 90]],
        ])->assertOk();

        $this->patchJson("/api/v1/inventory/daily-stocktake/{$sessionId}/closing", [
            'lines' => [['ingredientId' => $ingredientId, 'closingQty' => 70]],
        ])->assertOk()
            ->assertJsonPath('data.lines.0.overnightVarianceQty', 10)
            ->assertJsonPath('data.lines.0.operationalVarianceQty', 10);
    }

    public function test_approve_posts_consumption_and_marks_session_posted(): void
    {
        [$user, $outlet, $ingredientId, $menuId] = $this->seedStocktakeContext(withMenu: true);
        $this->seedFullAccounts((int) $outlet->id);
        $this->setDeferredTrigger('daily_stocktake');

        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-STK', $menuId);
        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        $businessDate = now()->toDateString();
        $sessionId = (int) $this->postJson('/api/v1/inventory/daily-stocktake', [
            'outletId' => (int) $outlet->id,
            'businessDate' => $businessDate,
        ])->json('data.id');

        $this->patchJson("/api/v1/inventory/daily-stocktake/{$sessionId}/opening", [
            'lines' => [['ingredientId' => $ingredientId, 'openingQty' => 100]],
        ])->assertOk();
        $this->patchJson("/api/v1/inventory/daily-stocktake/{$sessionId}/closing", [
            'lines' => [['ingredientId' => $ingredientId, 'closingQty' => 80]],
        ])->assertOk();
        $this->postJson("/api/v1/inventory/daily-stocktake/{$sessionId}/submit")->assertOk();
        $this->postJson("/api/v1/inventory/daily-stocktake/{$sessionId}/approve")->assertOk()
            ->assertJsonPath('data.status', DailyStocktakeSession::STATUS_POSTED);

        $this->assertDatabaseHas('inventory_consumption_queue', [
            'order_id' => $orderId,
            'status' => InventoryConsumptionQueue::STATUS_PROCESSED,
        ]);
        $this->assertNotNull(Order::query()->find($orderId)?->stock_deducted_at);
    }

    public function test_shift_close_skips_inventory_when_trigger_is_daily_stocktake(): void
    {
        [$user, $outlet, $ingredientId, $menuId] = $this->seedStocktakeContext(withMenu: true);
        $this->seedFullAccounts((int) $outlet->id);
        $this->setDeferredTrigger('daily_stocktake');

        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-SC-SKIP', $menuId);
        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        $this->postJson('/api/v1/orders/shift-close', [
            'outletId' => (int) $outlet->id,
            'confirm' => true,
        ])->assertOk();

        $this->assertDatabaseHas('inventory_consumption_queue', [
            'order_id' => $orderId,
            'status' => InventoryConsumptionQueue::STATUS_PENDING,
        ]);
        $this->assertNull(Order::query()->find($orderId)?->stock_deducted_at);
    }

    public function test_system_settings_persist_deferred_consumption_trigger(): void
    {
        $this->actingAsUserManagementApiAdministrator();
        $this->setStockEnforcementMode('deferred');

        $this->patchJson('/api/v1/system-settings', [
            'enableSplitBill' => true,
            'enableMultiPayment' => true,
            'confirmBeforePayment' => true,
            'enableQROrdering' => true,
            'stockEnforcementMode' => 'deferred',
            'deferredConsumptionTrigger' => 'daily_stocktake',
        ])->assertOk()
            ->assertJsonPath('data.deferredConsumptionTrigger', 'daily_stocktake');

        $this->assertSame(
            'daily_stocktake',
            (string) SystemSetting::query()->find(1)?->deferred_consumption_trigger,
        );
    }

    public function test_shift_close_still_processes_inventory_when_trigger_is_shift_close(): void
    {
        [$user, $outlet, , $menuId] = $this->seedStocktakeContext(withMenu: true);
        $this->seedFullAccounts((int) $outlet->id);
        $this->setDeferredTrigger('shift_close');

        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'POS-SC-DEF', $menuId);
        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        $this->postJson('/api/v1/orders/shift-close', [
            'outletId' => (int) $outlet->id,
            'confirm' => true,
        ])->assertOk();

        $this->assertDatabaseHas('inventory_consumption_queue', [
            'order_id' => $orderId,
            'status' => InventoryConsumptionQueue::STATUS_PROCESSED,
        ]);
    }

    /** @return array{0:\App\Models\User,1:Outlet,2:int,3?:int} */
    private function seedStocktakeContext(bool $withMenu = false): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Stocktake Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'stk-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $this->setStockEnforcementMode('deferred');

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
        $ingredientId = (int) $ingredient->id;
        DB::table('inventory_stocks')->insert([
            'ingredient_id' => $ingredientId,
            'outlet_id' => (int) $outlet->id,
            'stock' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $withMenu) {
            return [$user, $outlet, $ingredientId];
        }

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
            'inventory_item_id' => $ingredientId,
            'quantity' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $outlet, $ingredientId, $menuId];
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
                'deferred_consumption_trigger' => 'shift_close',
            ],
        );
    }

    private function setDeferredTrigger(string $trigger): void
    {
        SystemSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'enable_split_bill' => true,
                'enable_multi_payment' => true,
                'confirm_before_payment' => true,
                'enable_qr_ordering' => true,
                'enable_call_cashier' => true,
                'stock_enforcement_mode' => 'deferred',
                'enforce_stock_on_sale' => false,
                'deferred_consumption_trigger' => $trigger,
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
            ['category' => 'inventory_waste', 'code' => '5200', 'name' => 'Waste', 'type' => 'expense', 'subtype' => 'expense'],
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
