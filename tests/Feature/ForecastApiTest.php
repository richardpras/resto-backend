<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ForecastApiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_menu_forecasting_api_endpoints(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, menuPrice: 25000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 8000);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'API-FC', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 25000, 'total' => 25000, 'subtotal' => 25000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Menu', 'qty' => 3,
            'price' => 25000, 'line_total' => 75000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $q = '?outletId='.$outlet->id;

        $this->getJson('/api/v1/menu-forecasting/demand'.$q)->assertOk();
        $this->getJson('/api/v1/menu-forecasting/revenue'.$q)->assertOk();
        $this->getJson('/api/v1/menu-forecasting/food-cost'.$q)->assertOk();
        $this->getJson('/api/v1/menu-forecasting/ingredients'.$q)->assertOk();
        $this->getJson('/api/v1/menu-forecasting/production'.$q)->assertOk();
        $this->getJson('/api/v1/menu-forecasting/stock-risk'.$q)->assertOk();
        $this->getJson('/api/v1/menu-forecasting/summary'.$q)->assertOk();
        $this->getJson('/api/v1/menu-forecasting/menu-items/'.$menu['menuId'].$q)->assertOk();
        $this->postJson('/api/v1/menu-forecasting/snapshots/create'.$q)->assertCreated();
        $this->getJson('/api/v1/menu-forecasting/snapshots'.$q)->assertOk();
    }
}
