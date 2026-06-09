<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\ProductionForecastService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ProductionForecastTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_generates_production_recommendations_from_forecasted_demand(): void
    {
        $outlet = $this->createValuationOutlet();
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'PF-1', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 30000, 'total' => 30000, 'subtotal' => 30000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Menu', 'qty' => 15,
            'price' => 2000, 'line_total' => 30000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(ProductionForecastService::class)->forecastOutlet((int) $outlet->id);

        $this->assertNotEmpty($result['recommendations']);
        $this->assertArrayHasKey('productionPlan', $result);
    }
}
