<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryValuationCreationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_record_purchase_creates_valuation_row(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);

        $row = app(InventoryValuationService::class)->recordPurchase(
            (int) $ingredient->id,
            (int) $outlet->id,
            10,
            10000,
        );

        $this->assertDatabaseHas('inventory_valuations', [
            'ingredient_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'average_cost' => 10000,
            'last_purchase_cost' => 10000,
        ]);
        $this->assertSame(10.0, (float) $row->stock_quantity);
        $this->assertSame(100000.0, (float) $row->inventory_value);
    }
}
