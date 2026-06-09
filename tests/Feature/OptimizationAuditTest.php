<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuOptimizationService;
use App\Modules\Menu\Services\MenuOptimizationSnapshotService;
use App\Modules\Menu\Services\MenuSimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class OptimizationAuditTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_optimization_audit_events_are_recorded(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 30000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);

        $oid = (int) $outlet->id;
        app(MenuOptimizationService::class)->generateRecommendations($oid);
        app(MenuSimulationService::class)->simulatePrice($menu['menuId'], $oid, 32000);
        app(MenuOptimizationSnapshotService::class)->createSnapshot($oid);

        $events = PosEventLog::query()->pluck('event_type')->all();

        $this->assertContains('optimization_generated', $events);
        $this->assertContains('price_optimization_generated', $events);
        $this->assertContains('bundle_recommendation_generated', $events);
        $this->assertContains('ingredient_optimization_generated', $events);
        $this->assertContains('yield_optimization_generated', $events);
        $this->assertContains('simulation_executed', $events);
        $this->assertContains('optimization_snapshot_created', $events);
    }
}
