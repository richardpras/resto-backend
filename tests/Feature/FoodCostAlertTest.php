<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\FoodCostAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class FoodCostAlertTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_detects_food_cost_increase_above_threshold(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 100000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 20000);

        $orderItemId = DB::table('order_items')->insertGetId([
            'order_id' => DB::table('orders')->insertGetId([
                'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'FC-ALERT', 'source' => 'pos',
                'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
                'paid_total' => 100000, 'total' => 100000, 'subtotal' => 100000, 'tax' => 0,
                'created_at' => now()->subMonth(), 'updated_at' => now(),
            ]),
            'item_id' => (string) $menu['menuId'], 'name' => 'Menu', 'qty' => 1,
            'price' => 100000, 'line_total' => 100000, 'created_at' => now()->subMonth(), 'updated_at' => now(),
        ]);
        DB::table('order_item_cost_snapshots')->insert([
            'order_item_id' => $orderItemId, 'menu_item_id' => $menu['menuId'], 'outlet_id' => $outlet->id,
            'cost_per_unit' => 20000, 'total_cost' => 20000, 'average_cost_version' => 'v1',
            'created_at' => now()->subMonth(),
        ]);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 60000);

        $alerts = app(FoodCostAnalyticsService::class)->detectFoodCostIncrease((int) $outlet->id, 5.0);

        $this->assertNotEmpty($alerts);
    }
}
