<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\RecipeVersionService;
use App\Modules\Menu\Services\RevenueForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class RevenueForecastTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_predicts_revenue_from_demand_and_price(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, menuPrice: 50000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'RF-1', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 50000, 'total' => 50000, 'subtotal' => 50000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Menu', 'qty' => 5,
            'price' => 50000, 'line_total' => 250000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(RevenueForecastService::class)->forecastOutlet((int) $outlet->id);
        $item = $result['items'][0];

        $this->assertGreaterThan(0, $item['predictedRevenue']);
        $this->assertGreaterThan(0, $item['predictedMargin']);
    }
}
