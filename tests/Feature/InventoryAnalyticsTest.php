<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryAnalyticsService;
use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_inventory_turnover_uses_cogs_and_valuation(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 100, 10000);

        DB::table('stock_movements')->insert([
            'inventory_item_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'type' => 'sale',
            'quantity' => -10,
            'unit_cost' => 10000,
            'total_cost' => 100000,
            'source_type' => 'order_payment',
            'source_id' => 'ORD-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $turnover = app(InventoryAnalyticsService::class)->getInventoryTurnover((int) $outlet->id);

        $this->assertSame(100000.0, $turnover['cogs']);
        $this->assertGreaterThan(0, $turnover['inventoryTurnover']);
    }
}
