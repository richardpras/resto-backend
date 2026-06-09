<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Modules\Inventory\Services\InventoryAnalyticsService;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\AnalyticsSnapshotService;
use App\Modules\Menu\Services\ExecutiveAnalyticsService;
use App\Modules\Menu\Services\FoodCostAnalyticsService;
use App\Modules\Menu\Services\ProductionAnalyticsService;
use App\Modules\Menu\Services\ProfitabilityAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class AnalyticsAuditTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_analytics_audit_events_are_recorded(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, stock: 20);
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 80000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 20, 15000);

        $oid = (int) $outlet->id;
        app(ExecutiveAnalyticsService::class)->getExecutiveSummary($oid);
        app(FoodCostAnalyticsService::class)->getHighestFoodCostMenus($oid);
        app(ProfitabilityAnalyticsService::class)->getProfitabilityRanking($oid);
        app(ProductionAnalyticsService::class)->getSummary($oid);
        app(InventoryAnalyticsService::class)->getSummary($oid);
        app(AnalyticsSnapshotService::class)->createDailySnapshot($oid);

        $events = PosEventLog::query()->pluck('event_type')->all();

        $this->assertContains('executive_kpi_generated', $events);
        $this->assertContains('food_cost_analytics_generated', $events);
        $this->assertContains('profitability_analytics_generated', $events);
        $this->assertContains('production_analytics_generated', $events);
        $this->assertContains('inventory_analytics_generated', $events);
        $this->assertContains('analytics_snapshot_created', $events);
    }
}
