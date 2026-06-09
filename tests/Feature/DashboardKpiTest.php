<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\DashboardService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class DashboardKpiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_kpi_summary_includes_revenue_and_forecast_fields(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, menuPrice: 40000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 8000);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'DK-1', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 40000, 'total' => 40000, 'subtotal' => 40000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Menu', 'qty' => 2,
            'price' => 40000, 'line_total' => 80000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $kpis = app(DashboardService::class)->getKpis((int) $outlet->id);

        $this->assertArrayHasKey('revenue', $kpis);
        $this->assertArrayHasKey('foodCostPercent', $kpis);
        $this->assertArrayHasKey('averageMarginPercent', $kpis);
        $this->assertArrayHasKey('forecastRevenue', $kpis);
        $this->assertArrayHasKey('forecastMargin', $kpis);
    }
}
