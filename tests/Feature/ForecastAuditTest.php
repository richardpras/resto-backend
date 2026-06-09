<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\ForecastSnapshotService;
use App\Modules\Menu\Services\MenuForecastingService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ForecastAuditTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_forecast_audit_events_are_recorded(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 5000);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'FA-1', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 10000, 'total' => 10000, 'subtotal' => 10000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Menu', 'qty' => 5,
            'price' => 2000, 'line_total' => 10000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $oid = (int) $outlet->id;
        app(MenuForecastingService::class)->getSummary($oid);
        app(ForecastSnapshotService::class)->createSnapshot($oid);

        $events = PosEventLog::query()->pluck('event_type')->all();

        $this->assertContains('forecast_generated', $events);
        $this->assertContains('demand_forecast_generated', $events);
        $this->assertContains('revenue_forecast_generated', $events);
        $this->assertContains('forecast_snapshot_created', $events);
    }
}
