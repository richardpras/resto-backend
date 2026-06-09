<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\IngredientForecastService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class IngredientForecastTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_predicts_ingredient_usage_from_menu_demand(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.5);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'IF-1', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 20000, 'total' => 20000, 'subtotal' => 20000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Menu', 'qty' => 10,
            'price' => 2000, 'line_total' => 20000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(IngredientForecastService::class)->forecastOutlet((int) $outlet->id);

        $this->assertNotEmpty($result['ingredients']);
        $this->assertGreaterThan(0, $result['ingredients'][0]['predictedQuantity']);
    }
}
