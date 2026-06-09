<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class CostAuditTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_costing_audit_events_are_recorded(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 80000);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 20000);

        $service = app(RecipeCostService::class);
        $service->calculateMenuCostBreakdown($menu['menuId'], (int) $outlet->id, logCalculated: true);
        $service->updateYieldPercent($menu['menuId'], 90, outletId: (int) $outlet->id);
        $service->updateWastePercent($menu['menuId'], 3, outletId: (int) $outlet->id);
        $service->recalculateMenuCost($menu['menuId'], (int) $outlet->id);
        $service->calculateTheoreticalFoodCost($menu['menuId'], (int) $outlet->id);
        $service->calculateHistoricalCost($menu['menuId'], (int) $outlet->id);

        $events = PosEventLog::query()->pluck('event_type')->all();

        $this->assertContains('menu_cost_calculated', $events);
        $this->assertContains('yield_updated', $events);
        $this->assertContains('waste_updated', $events);
        $this->assertContains('menu_cost_recalculated', $events);
        $this->assertContains('food_cost_calculated', $events);
        $this->assertContains('historical_cost_compared', $events);
    }
}
