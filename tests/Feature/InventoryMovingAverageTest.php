<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryMovingAverageTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_weighted_average_formula_on_second_purchase(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $service = app(InventoryValuationService::class);

        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);
        $row = $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 12000);

        $expectedAvg = ((10 * 10000) + (10 * 12000)) / 20;
        $this->assertSame(round($expectedAvg, 4), round((float) $row->average_cost, 4));
        $this->assertSame(20.0, (float) $row->stock_quantity);
    }
}
