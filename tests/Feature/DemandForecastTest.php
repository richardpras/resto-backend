<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\DemandForecastService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class DemandForecastTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_predicts_demand_using_weighted_moving_average(): void
    {
        $outlet = $this->createValuationOutlet();
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        for ($i = 0; $i < 3; $i++) {
            $orderId = DB::table('orders')->insertGetId([
                'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'DF-'.$i, 'source' => 'pos',
                'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
                'paid_total' => 10000, 'total' => 10000, 'subtotal' => 10000, 'tax' => 0,
                'created_at' => now()->subDays($i), 'updated_at' => now()->subDays($i),
            ]);
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Menu', 'qty' => 10,
                'price' => 1000, 'line_total' => 10000, 'created_at' => now()->subDays($i), 'updated_at' => now()->subDays($i),
            ]);
        }

        $forecast = app(DemandForecastService::class)->forecastMenuItem($menu['menuId'], (int) $outlet->id);

        $this->assertGreaterThan(0, $forecast['predictedQuantity']);
        $this->assertGreaterThan(0, $forecast['confidenceScore']);
        $this->assertArrayHasKey('horizons', $forecast);
    }
}
