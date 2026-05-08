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

/**
 * Phase 11 — Backend Integrity Subagent A.
 *
 * Outlet security audit (integrity-oriented).
 * Verifies cross-outlet access is blocked across order, payment,
 * kitchen, accounting, and report surfaces; verifies QR spoofing
 * via mismatched outlet/table is rejected before persistence.
 */
class Phase11OutletIsolationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_other_outlet_order_is_invisible_to_user_with_restricted_outlet_scope(): void
    {
        [$user, $allowed, $forbidden] = $this->seedTwoOutletsForUser();
        $forbiddenOrderId = $this->insertRawOrder((int) $forbidden->id, 'P11OI-CROSS-1');

        $show = $this->getJson("/api/v1/orders/{$forbiddenOrderId}");
        $show->assertNotFound();

        $list = $this->getJson('/api/v1/orders?tenantId=1&outletId='.$allowed->id);
        $list->assertOk();
        $ids = collect($list->json('data') ?? [])->pluck('id')->all();
        $this->assertNotContains((string) $forbiddenOrderId, $ids, 'Allowed outlet listing must not return forbidden outlet orders.');
    }

    public function test_filtering_orders_by_forbidden_outlet_id_is_rejected(): void
    {
        [, , $forbidden] = $this->seedTwoOutletsForUser();

        $resp = $this->getJson('/api/v1/orders?tenantId=1&outletId='.$forbidden->id);
        $resp->assertUnprocessable();
    }

    public function test_payment_endpoints_block_cross_outlet_access(): void
    {
        [, , $forbidden] = $this->seedTwoOutletsForUser();
        $forbiddenOrderId = $this->insertRawOrder((int) $forbidden->id, 'P11OI-PAY-1');

        $this->postJson("/api/v1/orders/{$forbiddenOrderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ])->assertNotFound();

        $this->getJson("/api/v1/orders/{$forbiddenOrderId}/payments")->assertNotFound();
    }

    public function test_split_endpoints_block_cross_outlet_access(): void
    {
        [, , $forbidden] = $this->seedTwoOutletsForUser();
        $forbiddenOrderId = $this->insertRawOrder((int) $forbidden->id, 'P11OI-SPLIT-1');
        $orderItemId = (int) DB::table('order_items')->where('order_id', $forbiddenOrderId)->value('id');

        $this->postJson("/api/v1/orders/{$forbiddenOrderId}/splits", [
            'splitType' => 'by_item',
            'label' => 'Forbidden Split',
            'items' => [[
                'orderItemId' => $orderItemId,
                'qty' => 1,
                'amount' => 1000,
            ]],
        ])->assertNotFound();
    }

    public function test_kitchen_ticket_status_update_blocks_cross_outlet_access(): void
    {
        [, , $forbidden] = $this->seedTwoOutletsForUser();
        $forbiddenOrderId = $this->insertRawOrder((int) $forbidden->id, 'P11OI-KDS-1');
        $ticketId = (int) DB::table('kitchen_tickets')->insertGetId([
            'outlet_id' => (int) $forbidden->id,
            'order_id' => $forbiddenOrderId,
            'ticket_no' => 'P11OI-KDS-1',
            'status' => 'queued',
            'queued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->patchJson("/api/v1/kitchen/tickets/{$ticketId}/status", ['status' => 'in_progress'])
            ->assertNotFound();

        $kitchenList = $this->getJson('/api/v1/kitchen/tickets?outletId='.$forbidden->id);
        $kitchenList->assertUnprocessable();

        $this->assertSame(
            'queued',
            (string) DB::table('kitchen_tickets')->where('id', $ticketId)->value('status'),
            'Forbidden ticket status must not change.'
        );
    }

    public function test_accounting_journal_creation_blocks_cross_outlet_outlet_id(): void
    {
        [, , $forbidden] = $this->seedTwoOutletsForUser();
        $cashId = (int) Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $forbidden->id,
            'scope' => 'outlet',
            'category' => 'cash_bank',
            'code' => '1100',
            'name' => 'Cash F',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ])->id;
        $salesId = (int) Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $forbidden->id,
            'scope' => 'outlet',
            'category' => 'sales_revenue',
            'code' => '4100',
            'name' => 'Sales F',
            'type' => 'revenue',
            'subtype' => 'revenue',
            'is_active' => true,
        ])->id;

        $resp = $this->postJson('/api/v1/journals', [
            'journalDate' => now()->format('Y-m-d'),
            'description' => 'P11OI cross-outlet journal attempt',
            'status' => 'posted',
            'outletId' => (int) $forbidden->id,
            'postingKey' => 'p11oi-cross-1',
            'lines' => [
                ['accountId' => $cashId, 'debit' => 5000, 'credit' => 0],
                ['accountId' => $salesId, 'debit' => 0, 'credit' => 5000],
            ],
        ]);
        $resp->assertUnprocessable();

        $this->assertSame(
            0,
            DB::table('journals')->where('outlet_id', (int) $forbidden->id)->count(),
            'Cross-outlet journal must not be persisted.'
        );
    }

    public function test_report_endpoints_block_cross_outlet_outlet_id(): void
    {
        [, , $forbidden] = $this->seedTwoOutletsForUser();

        $this->getJson('/api/v1/reports/trial-balance?outletId='.$forbidden->id)->assertUnprocessable();
        $this->getJson('/api/v1/reports/profit-loss?outletId='.$forbidden->id)->assertUnprocessable();
        $this->getJson('/api/v1/reports/balance-sheet?outletId='.$forbidden->id)->assertUnprocessable();
    }

    public function test_qr_spoof_with_mismatched_outlet_and_table_is_rejected(): void
    {
        [, $allowed, $forbidden] = $this->seedTwoOutletsForUser();
        $forbiddenTable = RestaurantTable::query()->create([
            'outlet_id' => (int) $forbidden->id,
            'name' => 'P11OI-Forbidden-Table',
            'capacity' => 4,
            'status' => 'active',
        ]);
        $allowedMenu = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $allowed->id,
            'name' => 'P11OI Menu '.uniqid(),
            'category' => 'main',
            'price' => 25000,
            'available' => true,
        ]);

        $spoof = $this->postJson('/api/v1/qr-orders', [
            'outletId' => (int) $allowed->id,
            'tableId' => (int) $forbiddenTable->id,
            'customerName' => 'Spoof',
            'items' => [['menuItemId' => (int) $allowedMenu->id, 'qty' => 1]],
        ]);
        $spoof->assertUnprocessable();

        $this->assertSame(
            0,
            DB::table('qr_order_requests')->where('table_id', (int) $forbiddenTable->id)->count(),
            'QR spoof attempt must not persist a request row.'
        );
    }

    public function test_qr_confirm_for_request_in_forbidden_outlet_returns_not_found(): void
    {
        [$user, $allowed, $forbidden] = $this->seedTwoOutletsForUser();
        $forbiddenTable = RestaurantTable::query()->create([
            'outlet_id' => (int) $forbidden->id,
            'name' => 'P11OI-ForbiddenTable-2',
            'capacity' => 4,
            'status' => 'active',
        ]);
        $forbiddenMenu = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $forbidden->id,
            'name' => 'P11OI Forbidden Menu',
            'category' => 'main',
            'price' => 25000,
            'available' => true,
        ]);
        PosSession::query()->create([
            'outlet_id' => (int) $forbidden->id,
            'opened_by_user_id' => (int) $user->id,
            'status' => 'open',
            'opening_cash' => 50000,
            'opened_at' => now(),
        ]);

        $forbiddenRequestId = (int) DB::table('qr_order_requests')->insertGetId([
            'outlet_id' => (int) $forbidden->id,
            'table_id' => (int) $forbiddenTable->id,
            'request_code' => 'QRO-P11OI-FORBID-'.strtoupper((string) str()->random(6)),
            'customer_name' => 'Forbidden Guest',
            'status' => 'pending_cashier_confirmation',
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('qr_order_request_items')->insert([
            'qr_order_request_id' => $forbiddenRequestId,
            'menu_item_id' => (int) $forbiddenMenu->id,
            'qty' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $confirm = $this->postJson("/api/v1/qr-orders/{$forbiddenRequestId}/confirm");
        $confirm->assertNotFound();

        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $forbiddenRequestId,
            'status' => 'pending_cashier_confirmation',
        ]);
        $this->assertSame(
            0,
            DB::table('orders')->where('source', 'qr')->where('outlet_id', (int) $forbidden->id)->count(),
            'Forbidden QR confirm must not produce an order.'
        );
    }

    public function test_inventory_movement_into_forbidden_outlet_is_rejected(): void
    {
        [$user, $allowed, $forbidden] = $this->seedTwoOutletsForUser();
        $forbiddenIngredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $forbidden->id,
            'name' => 'P11OI Forbidden Ingredient',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 5,
        ]);
        DB::table('inventory_stocks')->insert([
            'ingredient_id' => (int) $forbiddenIngredient->id,
            'outlet_id' => (int) $forbidden->id,
            'stock' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resp = $this->postJson('/api/v1/stock-movements', [
            'inventory_item_id' => (int) $forbiddenIngredient->id,
            'outlet_id' => (int) $forbidden->id,
            'type' => 'adjustment',
            'quantity' => 5,
            'source_type' => 'cycle_count',
            'source_id' => 'P11OI-FORBID-MV',
        ]);
        $resp->assertUnprocessable();

        $this->assertDatabaseMissing('stock_movements', ['source_id' => 'P11OI-FORBID-MV']);
        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => (int) $forbiddenIngredient->id,
            'outlet_id' => (int) $forbidden->id,
            'stock' => 50.0,
        ]);
    }

    public function test_pos_session_open_into_forbidden_outlet_is_rejected(): void
    {
        [, , $forbidden] = $this->seedTwoOutletsForUser();

        $resp = $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => (int) $forbidden->id,
            'openingCash' => 10000,
        ]);
        $resp->assertUnprocessable();

        $this->assertSame(
            0,
            DB::table('pos_sessions')->where('outlet_id', (int) $forbidden->id)->count(),
            'POS session must not be opened in a forbidden outlet.'
        );
    }

    /**
     * Seed two outlets and authenticate a user with access only to the first outlet.
     *
     * @return array{0: User, 1: Outlet, 2: Outlet}
     */
    private function seedTwoOutletsForUser(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowed = Outlet::query()->create([
            'name' => 'P11OI Allowed',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p11oi-a-'.uniqid(),
        ]);
        $forbidden = Outlet::query()->create([
            'name' => 'P11OI Forbidden',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p11oi-f-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $allowed->id]);

        return [$user, $allowed, $forbidden];
    }

    private function insertRawOrder(int $outletId, string $code): int
    {
        $orderId = (int) DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'subtotal' => 10000,
            'tax' => 1000,
            'total' => 11000,
            'paid_total' => 0,
            'balance_due' => 11000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'item_id' => 'p11oi-itm-1',
            'name' => 'P11OI Item',
            'qty' => 1,
            'price' => 11000,
            'line_total' => 11000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }
}
