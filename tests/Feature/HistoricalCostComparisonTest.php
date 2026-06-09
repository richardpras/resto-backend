<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\OrderItemCostSnapshot;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class HistoricalCostComparisonTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_history_compares_snapshots_to_current_cost(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 50000);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);

        $orderItemId = DB::table('order_items')->insertGetId([
            'order_id' => DB::table('orders')->insertGetId([
                'tenant_id' => 1,
                'outlet_id' => $outlet->id,
                'code' => 'HIST-1',
                'source' => 'pos',
                'order_type' => 'Dine In',
                'status' => 'completed',
                'payment_status' => 'paid',
                'paid_total' => 50000,
                'total' => 50000,
                'subtotal' => 50000,
                'tax' => 0,
                'created_at' => now()->subDay(),
                'updated_at' => now(),
            ]),
            'item_id' => (string) $menu['menuId'],
            'name' => 'Menu',
            'qty' => 1,
            'price' => 50000,
            'line_total' => 50000,
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        OrderItemCostSnapshot::query()->create([
            'order_item_id' => $orderItemId,
            'menu_item_id' => $menu['menuId'],
            'outlet_id' => $outlet->id,
            'cost_per_unit' => 10000,
            'total_cost' => 10000,
            'average_cost_version' => 'v1',
            'created_at' => now()->subDay(),
        ]);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 15000);

        $history = app(RecipeCostService::class)->calculateHistoricalCost($menu['menuId'], (int) $outlet->id);

        // Weighted average: (10×10_000 + 10×15_000) / 20 = 12_500
        $this->assertSame(12500.0, $history['currentCost']);
        $this->assertCount(1, $history['history']);
        $this->assertSame(10000.0, $history['history'][0]['snapshotCost']);
        $this->assertSame(2500.0, $history['history'][0]['difference']);
        $this->assertSame(25.0, $history['history'][0]['variancePercent']);
    }
}
