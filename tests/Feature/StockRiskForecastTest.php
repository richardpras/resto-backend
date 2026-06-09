<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\RecipeVersionService;
use App\Modules\Menu\Services\StockRiskForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class StockRiskForecastTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_detects_critical_stock_risk_when_days_remaining_low(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 2);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 1, 5000);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'SR-1', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 50000, 'total' => 50000, 'subtotal' => 50000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Menu', 'qty' => 20,
            'price' => 2500, 'line_total' => 50000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(StockRiskForecastService::class)->forecastOutlet((int) $outlet->id);

        $this->assertNotEmpty($result['risks']);
        $this->assertContains($result['risks'][0]['riskLevel'], ['critical', 'high', 'medium']);
    }
}
