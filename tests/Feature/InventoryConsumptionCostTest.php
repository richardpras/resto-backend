<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryConsumptionCostTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_consumption_uses_average_cost_without_changing_average(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $service = app(InventoryValuationService::class);

        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);
        $unitCost = $service->recordConsumption((int) $ingredient->id, (int) $outlet->id, 3);

        $this->assertSame(10000.0, $unitCost);
        $this->assertDatabaseHas('inventory_valuations', [
            'ingredient_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'average_cost' => 10000,
            'stock_quantity' => 7,
            'inventory_value' => 70000,
        ]);
    }
}
