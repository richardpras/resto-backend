<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\PrepForecastService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class PrepForecastTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_prep_forecast_for_daily_period(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, stock: 50);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.2);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $today = now()->toDateString();
        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'PREP-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_total' => 20000,
            'total' => 20000,
            'subtotal' => 20000,
            'tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'item_id' => (string) $menu['menuId'],
            'name' => 'Menu',
            'qty' => 10,
            'price' => 2000,
            'line_total' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $forecast = app(PrepForecastService::class)->forecastDaily((int) $outlet->id, $today);

        $this->assertSame('daily', $forecast['period']);
        $this->assertNotEmpty($forecast['prepRequirements']);
        $this->assertSame(2.0, (float) $forecast['prepRequirements'][0]['prepQuantity']);
    }
}
