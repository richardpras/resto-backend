<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Inventory\Services\IngredientOutletStockLedger;
use App\Modules\Inventory\Services\RecipeStockDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

/**
 * Phase 11 — Backend Integrity Subagent A.
 *
 * Additive inventory integrity validation: recipe deduction accuracy,
 * exactly-once deduction, no negative drift, adjustment/waste ledger
 * consistency, outlet-scoped isolation, movement <-> accounting sync.
 */
class Phase11InventoryIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_recipe_deduction_uses_exact_qty_times_recipe_quantity(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11II Recipe-Exact');
        $this->seedFullAccounts((int) $outlet->id);
        [$ingA, $ingB, $menuId] = $this->seedRecipeContext((int) $outlet->id, 2.5, 1.0, 100, 80);

        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P11II-RX-1', $menuId, qty: 3, unitPrice: 50);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 150]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => (int) $ingA->id,
            'outlet_id' => (int) $outlet->id,
            'stock' => 92.5,
        ]);
        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => (int) $ingB->id,
            'outlet_id' => (int) $outlet->id,
            'stock' => 77.0,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => (int) $ingA->id,
            'outlet_id' => (int) $outlet->id,
            'type' => 'sale',
            'source_type' => 'order_payment',
            'source_id' => 'P11II-RX-1',
            'quantity' => 7.5,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => (int) $ingB->id,
            'outlet_id' => (int) $outlet->id,
            'type' => 'sale',
            'source_type' => 'order_payment',
            'source_id' => 'P11II-RX-1',
            'quantity' => 3.0,
        ]);
    }

    public function test_repeat_recipe_deduction_for_same_paid_order_is_exactly_once(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11II Once');
        $this->seedFullAccounts((int) $outlet->id);
        [$ingA, , $menuId] = $this->seedRecipeContext((int) $outlet->id, 2.0, 1.0, 50, 50);

        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P11II-ONCE-1', $menuId, qty: 1, unitPrice: 30);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        /** @var Order $order */
        $order = Order::query()->findOrFail($orderId);
        for ($i = 0; $i < 4; $i++) {
            app(RecipeStockDeductionService::class)->deductForPaidOrder($order);
        }

        $movementCount = DB::table('stock_movements')
            ->where('source_type', 'order_payment')
            ->where('source_id', 'P11II-ONCE-1')
            ->where('inventory_item_id', (int) $ingA->id)
            ->count();
        $this->assertSame(1, $movementCount, 'Recipe deduction must remain exactly-once after repeated invocation.');
        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => (int) $ingA->id,
            'outlet_id' => (int) $outlet->id,
            'stock' => 48.0,
        ]);
    }

    public function test_negative_stock_drift_blocked_under_repeated_deduction_attempts(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11II No-Negative');
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'name' => 'P11II Tight Ingredient',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 1,
        ]);
        DB::table('inventory_stocks')->insert([
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => (int) $outlet->id,
            'stock' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ledger = app(IngredientOutletStockLedger::class);
        $ledger->apply(
            (int) $outlet->id,
            (int) $ingredient->id,
            'sale',
            5.0,
            'manual_consumption',
            'P11II-NEG-1',
        );

        $this->expectException(HttpException::class);
        try {
            $ledger->apply(
                (int) $outlet->id,
                (int) $ingredient->id,
                'sale',
                1.0,
                'manual_consumption',
                'P11II-NEG-2',
            );
        } finally {
            $stock = (float) DB::table('inventory_stocks')
                ->where('ingredient_id', (int) $ingredient->id)
                ->where('outlet_id', (int) $outlet->id)
                ->value('stock');
            $this->assertSame(0.0, round($stock, 2), 'Stock must not drift below zero on rejected deductions.');
        }
    }

    public function test_adjustment_and_waste_movements_post_journals_with_matching_amounts(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11II Adjust-Sync');
        $this->seedAdjustmentAccounts((int) $outlet->id);

        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'name' => 'P11II Cabbage',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 4,
        ]);
        DB::table('inventory_stocks')->insert([
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => (int) $outlet->id,
            'stock' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adj = $this->postJson('/api/v1/stock-movements', [
            'inventory_item_id' => (int) $ingredient->id,
            'outlet_id' => (int) $outlet->id,
            'type' => 'adjustment',
            'quantity' => 6,
            'source_type' => 'cycle_count',
            'source_id' => 'P11II-ADJ-1',
        ]);
        $adj->assertCreated();
        $adjMovementId = (int) DB::table('stock_movements')
            ->where('source_type', 'cycle_count')
            ->where('source_id', 'P11II-ADJ-1')
            ->value('id');
        $adjTotalCost = (float) DB::table('stock_movements')->where('id', $adjMovementId)->value('total_cost');

        $waste = $this->postJson('/api/v1/stock-movements', [
            'inventory_item_id' => (int) $ingredient->id,
            'outlet_id' => (int) $outlet->id,
            'type' => 'waste',
            'quantity' => 4,
            'source_type' => 'waste_report',
            'source_id' => 'P11II-WASTE-1',
        ]);
        $waste->assertCreated();
        $wasteMovementId = (int) DB::table('stock_movements')
            ->where('source_type', 'waste_report')
            ->where('source_id', 'P11II-WASTE-1')
            ->value('id');
        $wasteTotalCost = (float) DB::table('stock_movements')->where('id', $wasteMovementId)->value('total_cost');

        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => (int) $outlet->id,
            'stock' => 52.0,
        ]);

        $adjJournalId = (int) DB::table('journals')
            ->where('source_type', 'inventory_adjustment')
            ->where('source_id', (string) $adjMovementId)
            ->value('id');
        $this->assertGreaterThan(0, $adjJournalId, 'Adjustment must produce a journal.');
        $adjDebit = (float) DB::table('journal_entries')->where('journal_id', $adjJournalId)->sum('debit');
        $adjCredit = (float) DB::table('journal_entries')->where('journal_id', $adjJournalId)->sum('credit');
        $this->assertSame(round($adjDebit, 2), round($adjCredit, 2), 'Adjustment journal must be balanced.');
        $this->assertSame(round($adjTotalCost, 2), round($adjDebit, 2), 'Adjustment journal total must match movement total_cost.');

        $wasteJournalId = (int) DB::table('journals')
            ->where('source_type', 'inventory_waste')
            ->where('source_id', (string) $wasteMovementId)
            ->value('id');
        $this->assertGreaterThan(0, $wasteJournalId, 'Waste must produce a journal.');
        $wasteDebit = (float) DB::table('journal_entries')->where('journal_id', $wasteJournalId)->sum('debit');
        $wasteCredit = (float) DB::table('journal_entries')->where('journal_id', $wasteJournalId)->sum('credit');
        $this->assertSame(round($wasteDebit, 2), round($wasteCredit, 2), 'Waste journal must be balanced.');
        $this->assertSame(round($wasteTotalCost, 2), round($wasteDebit, 2), 'Waste journal total must match movement total_cost.');
    }

    public function test_outlet_scoped_inventory_isolation_for_recipe_deduction(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11II Iso A');
        $otherOutlet = Outlet::query()->create([
            'name' => 'P11II Iso B',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p11ii-other-'.uniqid(),
        ]);

        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'name' => 'P11II Shared SKU',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 2,
        ]);
        DB::table('inventory_stocks')->insert([
            ['ingredient_id' => (int) $ingredient->id, 'outlet_id' => (int) $outlet->id, 'stock' => 60, 'created_at' => now(), 'updated_at' => now()],
            ['ingredient_id' => (int) $ingredient->id, 'outlet_id' => (int) $otherOutlet->id, 'stock' => 60, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $menuId = (int) DB::table('menu_items')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'name' => 'P11II Iso Menu',
            'price' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('menu_recipes')->insert([
            'menu_item_id' => $menuId,
            'inventory_item_id' => (int) $ingredient->id,
            'quantity' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedFullAccounts((int) $outlet->id);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P11II-ISO-1', $menuId, qty: 2, unitPrice: 30);
        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 60]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => (int) $outlet->id,
            'stock' => 50.0,
        ]);
        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => (int) $otherOutlet->id,
            'stock' => 60.0,
        ]);
        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('inventory_item_id', (int) $ingredient->id)
                ->where('outlet_id', (int) $otherOutlet->id)
                ->count(),
            'Recipe deduction must never write movements into a different outlet.'
        );
    }

    public function test_paid_order_records_unit_cost_and_total_cost_for_cogs_sync(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11II Cost-Sync');
        $this->seedFullAccounts((int) $outlet->id);
        [$ingA, , $menuId] = $this->seedRecipeContext((int) $outlet->id, 1.5, 1.0, 60, 60, ingredientPriceA: 2.5);

        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P11II-COST-1', $menuId, qty: 2, unitPrice: 40);
        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 80]],
        ])->assertOk();

        $movement = DB::table('stock_movements')
            ->where('source_type', 'order_payment')
            ->where('source_id', 'P11II-COST-1')
            ->where('inventory_item_id', (int) $ingA->id)
            ->first();
        $this->assertNotNull($movement, 'Recipe deduction must record a stock movement row.');
        $this->assertSame(2.5, round((float) $movement->unit_cost, 2), 'Movement must record ingredient unit cost.');
        $this->assertSame(round(3.0 * 2.5, 2), round((float) $movement->total_cost, 2), 'total_cost must be unit_cost * quantity.');

        $journalId = (int) DB::table('journals')
            ->where('source_type', 'order_payment')
            ->where('source_id', (string) $orderId)
            ->value('id');
        $cogsDebit = (float) DB::table('journal_entries')
            ->where('journal_id', $journalId)
            ->where('memo', 'COGS recognition')
            ->sum('debit');
        $movementsTotal = (float) DB::table('stock_movements')
            ->where('source_type', 'order_payment')
            ->where('source_id', 'P11II-COST-1')
            ->sum('total_cost');
        $this->assertSame(round($movementsTotal, 2), round($cogsDebit, 2), 'COGS journal line must equal sum of movement total_cost.');
    }

    public function test_list_stock_movements_accepts_query_keys_consistent_with_ingredients(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('P11II Stock-GET');
        $camel = $this->getJson('/api/v1/stock-movements?tenantId=1&outletId='.(int) $outlet->id.'&perPage=10');
        $camel->assertOk();
        $camel->assertJsonStructure(['data', 'meta']);

        $snake = $this->getJson('/api/v1/stock-movements?tenant_id=1&outlet_id='.(int) $outlet->id.'&per_page=10');
        $snake->assertOk();
    }

    /** @return array{0: User, 1: Outlet} */
    private function actAsAdminWithOutlet(string $name): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p11ii-'.uniqid(),
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
        float $ingredientPriceA = 2.0,
        float $ingredientPriceB = 1.0
    ): array {
        $ingA = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'P11II Ing A '.uniqid(),
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => $ingredientPriceA,
        ]);
        $ingB = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'P11II Ing B '.uniqid(),
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => $ingredientPriceB,
        ]);
        DB::table('inventory_stocks')->insert([
            ['ingredient_id' => (int) $ingA->id, 'outlet_id' => $outletId, 'stock' => $stockA, 'created_at' => now(), 'updated_at' => now()],
            ['ingredient_id' => (int) $ingB->id, 'outlet_id' => $outletId, 'stock' => $stockB, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $menuId = (int) DB::table('menu_items')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'P11II Menu '.uniqid(),
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
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'P11II-T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $session = PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $userId,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);
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
            'posSessionId' => (int) $session->id,
            'tableId' => (int) $table->id,
            'items' => [
                ['id' => (string) $menuItemId, 'name' => 'P11II Menu', 'qty' => $qty, 'price' => $unitPrice],
            ],
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'payments' => [],
        ]);
        $resp->assertCreated();

        return (int) $resp->json('data.id');
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

    private function seedAdjustmentAccounts(int $outletId): void
    {
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
        Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'waste_expense',
            'code' => '5200',
            'name' => 'Waste Expense',
            'type' => 'expense',
            'subtype' => 'operational_expense',
            'is_active' => true,
        ]);
        Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'stock_adjustment',
            'code' => '5300',
            'name' => 'Stock Adjustment',
            'type' => 'expense',
            'subtype' => 'operational_expense',
            'is_active' => true,
        ]);
    }
}
