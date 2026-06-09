<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\OrderItemCostSnapshot;
use App\Models\Modules\Orders\Domain\OrderItemRecipeSnapshot;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Inventory\Services\RecipeStockDeductionService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class CostSnapshotAlignmentTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_cost_snapshot_links_to_recipe_snapshot_and_version(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        DB::table('inventory_stocks')->updateOrInsert(
            ['ingredient_id' => $ingredient->id, 'outlet_id' => $outlet->id],
            ['stock' => 100, 'created_at' => now(), 'updated_at' => now()],
        );
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 100, 10000);

        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.2);
        $version = app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'ALIGN-'.uniqid(),
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
            'name' => 'Menu',
            'qty' => 1,
            'price' => 30000,
            'line_total' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = \App\Models\Modules\Orders\Domain\Order::query()->with('items')->findOrFail($orderId);
        app(RecipeStockDeductionService::class)->deductForPaidOrder($order);

        $recipeSnapshot = OrderItemRecipeSnapshot::query()->where('order_item_id', $orderItemId)->firstOrFail();
        $costSnapshot = OrderItemCostSnapshot::query()->where('order_item_id', $orderItemId)->firstOrFail();

        $this->assertSame((int) $version->id, (int) $costSnapshot->recipe_version_id);
        $this->assertSame((int) $version->id, (int) $recipeSnapshot->recipe_version_id);
        $this->assertSame((int) $version->version_number, (int) $recipeSnapshot->version_number);
        $this->assertStringContainsString('recipe-v', (string) $costSnapshot->average_cost_version);
        $this->assertSame(2000.0, (float) $costSnapshot->cost_per_unit);
    }
}
