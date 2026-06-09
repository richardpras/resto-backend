<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\DashboardService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_generates_executive_dashboard_summary(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, menuPrice: 50000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $summary = app(DashboardService::class)->getSummary((int) $outlet->id);

        $this->assertArrayHasKey('kpis', $summary);
        $this->assertArrayHasKey('engineering', $summary);
        $this->assertArrayHasKey('optimization', $summary);
        $this->assertArrayHasKey('automation', $summary);
        $this->assertArrayHasKey('forecasting', $summary);
        $this->assertArrayHasKey('inventory', $summary);
        $this->assertArrayHasKey('health', $summary);
    }
}
