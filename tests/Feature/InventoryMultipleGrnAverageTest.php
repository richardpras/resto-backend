<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryMultipleGrnAverageTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_multiple_receipts_at_different_costs_update_average(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $service = app(InventoryValuationService::class);

        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 5, 10500, 101);
        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 5, 9500, 102);
        $row = $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000, 103);

        $expectedAvg = ((5 * 10500) + (5 * 9500) + (10 * 10000)) / 20;
        $this->assertSame(round($expectedAvg, 4), round((float) $row->average_cost, 4));
        $this->assertSame(10000.0, (float) $row->last_purchase_cost);
        $this->assertSame(103, (int) $row->last_grn_id);
    }
}
