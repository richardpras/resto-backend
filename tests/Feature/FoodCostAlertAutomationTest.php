<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\AlertEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class FoodCostAlertAutomationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_triggers_food_cost_alert_when_above_threshold(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 50000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 25000);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'FC-AUTO', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 50000, 'total' => 50000, 'subtotal' => 50000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Menu', 'qty' => 1,
            'price' => 50000, 'line_total' => 50000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_item_cost_snapshots')->insert([
            'order_item_id' => DB::table('order_items')->where('order_id', $orderId)->value('id'),
            'menu_item_id' => $menu['menuId'], 'outlet_id' => $outlet->id,
            'cost_per_unit' => 25000, 'total_cost' => 25000, 'average_cost_version' => 'v1',
            'created_at' => now(),
        ]);

        app(AlertEvaluationService::class)->evaluateFoodCost((int) $outlet->id);

        $this->assertTrue(
            AutomationAlert::query()
                ->where('outlet_id', $outlet->id)
                ->where('alert_type', 'food_cost')
                ->where('status', 'open')
                ->exists()
        );
    }
}
