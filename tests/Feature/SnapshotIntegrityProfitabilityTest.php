<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\OrderItemCostSnapshot;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\HistoricalMarginService;
use App\Modules\Menu\Services\MenuProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class SnapshotIntegrityProfitabilityTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_profitability_queries_never_modify_cost_snapshots(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 100000);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 25000);

        $orderItemId = DB::table('order_items')->insertGetId([
            'order_id' => DB::table('orders')->insertGetId([
                'tenant_id' => 1,
                'outlet_id' => $outlet->id,
                'code' => 'SNAP-PROF-1',
                'source' => 'pos',
                'order_type' => 'Dine In',
                'status' => 'completed',
                'payment_status' => 'paid',
                'paid_total' => 100000,
                'total' => 100000,
                'subtotal' => 100000,
                'tax' => 0,
                'created_at' => now()->subDay(),
                'updated_at' => now(),
            ]),
            'item_id' => (string) $menu['menuId'],
            'name' => 'Menu',
            'qty' => 1,
            'price' => 100000,
            'line_total' => 100000,
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        OrderItemCostSnapshot::query()->create([
            'order_item_id' => $orderItemId,
            'menu_item_id' => $menu['menuId'],
            'outlet_id' => $outlet->id,
            'cost_per_unit' => 25000,
            'total_cost' => 25000,
            'average_cost_version' => 'v1',
            'created_at' => now()->subDay(),
        ]);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 50000);

        app(MenuProfitabilityService::class)->calculateProfitability($menu['menuId'], (int) $outlet->id);
        app(HistoricalMarginService::class)->compareHistoricalMargins($menu['menuId'], (int) $outlet->id);

        $snapshot = OrderItemCostSnapshot::query()->where('order_item_id', $orderItemId)->firstOrFail();

        $this->assertSame(25000.0, (float) $snapshot->cost_per_unit);
        $this->assertSame(25000.0, (float) $snapshot->total_cost);
        $this->assertSame(1, OrderItemCostSnapshot::query()->where('order_item_id', $orderItemId)->count());
    }
}
