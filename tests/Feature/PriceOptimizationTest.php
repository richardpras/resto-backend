<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuEngineeringMatrixService;
use App\Modules\Menu\Services\PriceOptimizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class PriceOptimizationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_price_optimization_suggests_increase_for_plowhorse(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 2, menuPrice: 25000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 8000);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'PO-1', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 500000, 'total' => 500000, 'subtotal' => 500000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Plow', 'qty' => 50,
            'price' => 25000, 'line_total' => 1250000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $analysis = app(PriceOptimizationService::class)->analyzeMenuItem(
            $menu['menuId'],
            (int) $outlet->id,
            MenuEngineeringMatrixService::PLOWHORSE,
        );

        $this->assertSame(25000.0, $analysis['currentPrice']);
        $this->assertGreaterThan(25000.0, $analysis['suggestedPrice']);
        $this->assertGreaterThan($analysis['currentMarginPercent'], $analysis['projectedMarginPercent']);
        $this->assertSame('increase', $analysis['suggestedDirection']);
    }
}
