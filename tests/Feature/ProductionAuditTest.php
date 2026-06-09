<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Modules\Menu\Services\PrepForecastService;
use App\Modules\Menu\Services\ProductionPlanningService;
use App\Modules\Menu\Services\ProductionShortageService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ProductionAuditTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_production_audit_events_are_recorded(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, stock: 10);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.2);

        $versionService = app(RecipeVersionService::class);
        $versionService->createVersion($menu['menuId'], [
            ['ingredientId' => (int) $ingredient->id, 'quantity' => 0.2],
        ], activate: true);
        $draft = $versionService->createVersion($menu['menuId'], [
            ['ingredientId' => (int) $ingredient->id, 'quantity' => 0.3],
        ], activate: false);
        $versionService->archiveVersion($menu['menuId'], (int) $draft->id);

        $demands = [['menuItemId' => $menu['menuId'], 'quantity' => 20]];
        app(ProductionPlanningService::class)->generateProductionPlan((int) $outlet->id, $demands);
        app(ProductionShortageService::class)->detectShortages((int) $outlet->id, $demands);
        app(PrepForecastService::class)->forecastDaily((int) $outlet->id, now()->toDateString());

        $events = PosEventLog::query()->pluck('event_type')->all();

        $this->assertContains('recipe_version_created', $events);
        $this->assertContains('recipe_version_activated', $events);
        $this->assertContains('recipe_version_archived', $events);
        $this->assertContains('production_plan_generated', $events);
        $this->assertContains('ingredient_demand_generated', $events);
        $this->assertContains('prep_forecast_generated', $events);
        $this->assertContains('production_shortage_detected', $events);
    }
}
