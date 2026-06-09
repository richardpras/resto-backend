<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\ProductionAnalyticsService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ProductionAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_most_produced_menus_from_paid_orders(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.2);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'PROD-A-1', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 50000, 'total' => 50000, 'subtotal' => 50000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Menu',
            'qty' => 25, 'price' => 2000, 'line_total' => 50000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = app(ProductionAnalyticsService::class)->getMostProducedMenus((int) $outlet->id);

        $this->assertSame(25.0, $rows[0]['quantitySold']);
    }
}
