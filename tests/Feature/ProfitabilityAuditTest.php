<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\HistoricalMarginService;
use App\Modules\Menu\Services\MenuPriceSimulationService;
use App\Modules\Menu\Services\MenuProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ProfitabilityAuditTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_profitability_audit_events_are_recorded(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 100000);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 30000);

        app(MenuProfitabilityService::class)->calculateProfitability($menu['menuId'], (int) $outlet->id);
        app(HistoricalMarginService::class)->compareHistoricalMargins($menu['menuId'], (int) $outlet->id);
        app(MenuPriceSimulationService::class)->simulate($menu['menuId'], (int) $outlet->id, [110000]);

        $events = PosEventLog::query()->pluck('event_type')->all();

        $this->assertContains('menu_profitability_calculated', $events);
        $this->assertContains('profitability_classification_generated', $events);
        $this->assertContains('historical_margin_compared', $events);
        $this->assertContains('menu_profitability_simulated', $events);
    }
}
