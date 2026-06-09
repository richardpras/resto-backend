<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\ExecutiveAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ExecutiveAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_executive_summary_aggregates_kpis(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 100000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 40000);

        $summary = app(ExecutiveAnalyticsService::class)->getExecutiveSummary((int) $outlet->id);

        $this->assertArrayHasKey('averageFoodCostPercent', $summary);
        $this->assertArrayHasKey('averageMarginPercent', $summary);
        $this->assertArrayHasKey('inventoryValue', $summary);
        $this->assertArrayHasKey('totalOrders', $summary);
    }
}
