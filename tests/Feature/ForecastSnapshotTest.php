<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\ForecastSnapshot;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\ForecastSnapshotService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ForecastSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_forecast_snapshot_is_idempotent(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, menuPrice: 30000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 5000);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'FS-1', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 30000, 'total' => 30000, 'subtotal' => 30000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => (string) $menu['menuId'], 'name' => 'Menu', 'qty' => 5,
            'price' => 30000, 'line_total' => 150000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(ForecastSnapshotService::class);
        $date = now()->toDateString();
        $forecastDate = now()->addDay()->toDateString();
        $first = $service->createSnapshot((int) $outlet->id, $date, $forecastDate);
        $second = $service->createSnapshot((int) $outlet->id, $date, $forecastDate);

        $this->assertSame($first->count(), $second->count());
        $this->assertGreaterThan(0, ForecastSnapshot::query()->where('outlet_id', $outlet->id)->count());
    }
}
