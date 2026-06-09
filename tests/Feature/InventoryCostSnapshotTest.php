<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\OrderItemCostSnapshot;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Inventory\Services\RecipeStockDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryCostSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_payment_creates_immutable_cost_snapshot(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, price: 5000);
        DB::table('inventory_stocks')->updateOrInsert(
            ['ingredient_id' => $ingredient->id, 'outlet_id' => $outlet->id],
            ['stock' => 50, 'created_at' => now(), 'updated_at' => now()],
        );
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 50, 10500);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 2);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'SNAP-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_total' => 30000,
            'total' => 30000,
            'subtotal' => 30000,
            'tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderItemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'item_id' => (string) $menu['menuId'],
            'name' => 'Snap Menu',
            'qty' => 3,
            'price' => 10000,
            'line_total' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = \App\Models\Modules\Orders\Domain\Order::query()->with('items')->findOrFail($orderId);
        app(RecipeStockDeductionService::class)->deductForPaidOrder($order);

        $snapshot = OrderItemCostSnapshot::query()->where('order_item_id', $orderItemId)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(21000.0, (float) $snapshot->cost_per_unit);
        $this->assertSame(63000.0, (float) $snapshot->total_cost);

        DB::table('ingredients')->where('id', $ingredient->id)->update(['price' => 99999]);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 1, 20000);

        $snapshot->refresh();
        $this->assertSame(21000.0, (float) $snapshot->cost_per_unit);
    }
}
