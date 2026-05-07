<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Inventory\Services\RecipeStockDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase7InventoryLedgerIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_recipe_deduction_correctness_after_full_payment_only(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('P7 Main');
        [$ingredientA, $ingredientB, $menuId] = $this->seedRecipeContext($outlet->id);
        $orderId = $this->createConfirmedOrder($outlet->id, $menuId, 'P7-RCP-1', 2, 60);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'partial');

        $this->assertDatabaseHas('inventory_stocks', ['ingredient_id' => $ingredientA->id, 'outlet_id' => $outlet->id, 'stock' => 100.0]);
        $this->assertDatabaseHas('inventory_stocks', ['ingredient_id' => $ingredientB->id, 'outlet_id' => $outlet->id, 'stock' => 80.0]);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'transfer', 'amount' => 40]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $this->assertDatabaseHas('inventory_stocks', ['ingredient_id' => $ingredientA->id, 'outlet_id' => $outlet->id, 'stock' => 96.0]);
        $this->assertDatabaseHas('inventory_stocks', ['ingredient_id' => $ingredientB->id, 'outlet_id' => $outlet->id, 'stock' => 78.0]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $ingredientA->id,
            'outlet_id' => $outlet->id,
            'type' => 'sale',
            'quantity' => 4.0,
            'source_type' => 'order_payment',
            'source_id' => 'P7-RCP-1',
            'unit_cost' => 2.0,
            'total_cost' => 8.0,
        ]);
    }

    public function test_duplicate_payment_idempotency_does_not_double_deduct_stock(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('P7 Idempotency');
        [$ingredientA, , $menuId] = $this->seedRecipeContext($outlet->id);
        $orderId = $this->createConfirmedOrder($outlet->id, $menuId, 'P7-IDEMP-1', 1, 30);

        $payload = [
            'idempotencyKey' => 'p7-stock-pay-dup-1',
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ];
        $this->postJson("/api/v1/orders/{$orderId}/payments", $payload)->assertOk();
        $this->postJson("/api/v1/orders/{$orderId}/payments", $payload)->assertUnprocessable();

        $this->assertDatabaseHas('inventory_stocks', ['ingredient_id' => $ingredientA->id, 'outlet_id' => $outlet->id, 'stock' => 98.0]);
        $this->assertEquals(
            1,
            DB::table('stock_movements')
                ->where('source_type', 'order_payment')
                ->where('source_id', 'P7-IDEMP-1')
                ->where('inventory_item_id', $ingredientA->id)
                ->count()
        );
    }

    public function test_repeat_deduction_call_is_idempotent_for_same_paid_order(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('P7 Concurrency');
        [$ingredientA, , $menuId] = $this->seedRecipeContext($outlet->id);
        $orderId = $this->createConfirmedOrder($outlet->id, $menuId, 'P7-CONCUR-1', 1, 30);
        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        /** @var Order $order */
        $order = Order::query()->findOrFail($orderId);
        app(RecipeStockDeductionService::class)->deductForPaidOrder($order);

        $this->assertEquals(
            1,
            DB::table('stock_movements')
                ->where('source_type', 'order_payment')
                ->where('source_id', 'P7-CONCUR-1')
                ->where('inventory_item_id', $ingredientA->id)
                ->count()
        );
        $this->assertDatabaseHas('inventory_stocks', ['ingredient_id' => $ingredientA->id, 'outlet_id' => $outlet->id, 'stock' => 98.0]);
    }

    public function test_adjustment_and_waste_flows_are_logged_in_ledger_and_audit(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('P7 Adjust');
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Cabbage',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 1.5,
        ]);

        DB::table('inventory_stocks')->insert([
            'ingredient_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'stock' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/stock-movements', [
            'inventory_item_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'type' => 'adjustment',
            'quantity' => 5,
            'source_type' => 'cycle_count',
            'source_id' => 'P7-ADJ-1',
        ])->assertCreated();

        $this->postJson('/api/v1/stock-movements', [
            'inventory_item_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'type' => 'waste',
            'quantity' => 3,
            'source_type' => 'waste_report',
            'source_id' => 'P7-WASTE-1',
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_stocks', ['ingredient_id' => $ingredient->id, 'outlet_id' => $outlet->id, 'stock' => 22.0]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'type' => 'adjustment',
            'source_id' => 'P7-ADJ-1',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'type' => 'waste',
            'source_id' => 'P7-WASTE-1',
        ]);
        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'inventory.movement.recorded',
            'entity_type' => 'stock_movement',
            'outlet_id' => $outlet->id,
        ]);
    }

    public function test_outlet_scope_authorization_blocks_inventory_access_for_other_outlet(): void
    {
        [$user, $allowedOutlet] = $this->actAsAdminWithOutlet('P7 Allowed');
        $forbiddenOutlet = Outlet::query()->create([
            'name' => 'P7 Forbidden',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p7-forbidden-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$allowedOutlet->id]);

        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $forbiddenOutlet->id,
            'name' => 'Forbidden Ingredient',
            'type' => 'ingredient',
            'unit' => 'pcs',
            'stock' => 0,
            'min' => 0,
            'price' => 3,
        ]);

        DB::table('inventory_stocks')->insert([
            'ingredient_id' => $ingredient->id,
            'outlet_id' => $forbiddenOutlet->id,
            'stock' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/stock-movements', [
            'inventory_item_id' => $ingredient->id,
            'outlet_id' => $forbiddenOutlet->id,
            'type' => 'adjustment',
            'quantity' => 2,
            'source_type' => 'cycle_count',
            'source_id' => 'P7-FORBIDDEN-1',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('stock_movements', [
            'source_id' => 'P7-FORBIDDEN-1',
        ]);
    }

    /** @return array{0:\App\Models\User,1:Outlet} */
    private function actAsAdminWithOutlet(string $name): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p7-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }

    /** @return array{0:Ingredient,1:Ingredient,2:int} */
    private function seedRecipeContext(int $outletId): array
    {
        $ingredientA = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Chicken',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 2,
        ]);
        $ingredientB = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Sauce',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 1,
        ]);

        DB::table('inventory_stocks')->insert([
            ['ingredient_id' => $ingredientA->id, 'outlet_id' => $outletId, 'stock' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['ingredient_id' => $ingredientB->id, 'outlet_id' => $outletId, 'stock' => 80, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $menuId = (int) DB::table('menu_items')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'P7 Menu '.uniqid(),
            'price' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('menu_recipes')->insert([
            ['menu_item_id' => $menuId, 'inventory_item_id' => $ingredientA->id, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['menu_item_id' => $menuId, 'inventory_item_id' => $ingredientB->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$ingredientA, $ingredientB, $menuId];
    }

    private function createConfirmedOrder(int $outletId, int $menuId, string $code, int $qty, float $total): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'P7-T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $session = PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => (int) Auth::id(),
            'status' => 'open',
            'opening_cash' => 50000,
            'opened_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/orders', [
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
                ['id' => (string) $menuId, 'name' => 'P7 Menu', 'qty' => $qty, 'price' => $total / max(1, $qty)],
            ],
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'payments' => [],
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }
}
