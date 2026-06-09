<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\MenuPopularityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class MenuPopularityTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_calculates_popularity_percent_from_sales(): void
    {
        $outlet = $this->createValuationOutlet();
        $menuA = $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);
        $menuB = $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'POP-1', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 30000, 'total' => 30000, 'subtotal' => 30000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            ['order_id' => $orderId, 'item_id' => (string) $menuA['menuId'], 'name' => 'A', 'qty' => 30, 'price' => 500, 'line_total' => 15000, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $orderId, 'item_id' => (string) $menuB['menuId'], 'name' => 'B', 'qty' => 10, 'price' => 500, 'line_total' => 5000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $service = app(MenuPopularityService::class);
        $this->assertSame(75.0, $service->calculatePopularityPercent($menuA['menuId'], (int) $outlet->id));
        $this->assertSame(25.0, $service->calculatePopularityPercent($menuB['menuId'], (int) $outlet->id));
    }
}
