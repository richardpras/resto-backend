<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Services\IngredientOutletStockLedger;
use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryValuationRebuildTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_recalculate_rebuilds_from_stock_movements(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $ledger = app(IngredientOutletStockLedger::class);

        $ledger->apply((int) $outlet->id, (int) $ingredient->id, 'purchase', 10, 'GR', 'GR-1', [
            'cost_method' => 'moving_average',
            'unit_cost' => 10000,
        ]);
        $ledger->apply((int) $outlet->id, (int) $ingredient->id, 'purchase', 10, 'GR', 'GR-2', [
            'cost_method' => 'moving_average',
            'unit_cost' => 12000,
        ]);

        app(InventoryValuationService::class)->recalculate((int) $ingredient->id, (int) $outlet->id);

        $expectedAvg = ((10 * 10000) + (10 * 12000)) / 20;
        $this->assertDatabaseHas('inventory_valuations', [
            'ingredient_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'average_cost' => round($expectedAvg, 4),
        ]);
        $this->assertSame(2, StockMovement::query()->where('inventory_item_id', $ingredient->id)->count());
    }
}
