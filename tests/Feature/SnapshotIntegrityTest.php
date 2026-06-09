<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuRecipeCostSetting;
use App\Models\Modules\Orders\Domain\OrderItemCostSnapshot;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Inventory\Services\RecipeStockDeductionService;
use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class SnapshotIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_snapshots_store_yield_waste_adjusted_cost_and_remain_immutable(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, price: 5000);
        DB::table('inventory_stocks')->updateOrInsert(
            ['ingredient_id' => $ingredient->id, 'outlet_id' => $outlet->id],
            ['stock' => 50, 'created_at' => now(), 'updated_at' => now()],
        );
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 50, 10000);

        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 100000);
        MenuRecipeCostSetting::query()->create([
            'menu_item_id' => $menu['menuId'],
            'yield_percent' => 80,
            'waste_percent' => 5,
            'is_active' => true,
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'SNAP-YW-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_total' => 100000,
            'total' => 100000,
            'subtotal' => 100000,
            'tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'item_id' => (string) $menu['menuId'],
            'name' => 'Menu',
            'qty' => 1,
            'price' => 100000,
            'line_total' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = \App\Models\Modules\Orders\Domain\Order::query()->with('items')->findOrFail($orderId);
        app(RecipeStockDeductionService::class)->deductForPaidOrder($order);

        $snapshot = OrderItemCostSnapshot::query()->where('menu_item_id', $menu['menuId'])->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(13125.0, (float) $snapshot->cost_per_unit);

        MenuRecipeCostSetting::query()->where('menu_item_id', $menu['menuId'])->update(['yield_percent' => 100, 'waste_percent' => 0]);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 1, 99999);

        $snapshot->refresh();
        $this->assertSame(13125.0, (float) $snapshot->cost_per_unit);

        $current = app(RecipeCostService::class)->calculateMenuCost($menu['menuId'], (int) $outlet->id);
        $this->assertNotSame(13125.0, $current);
    }
}
