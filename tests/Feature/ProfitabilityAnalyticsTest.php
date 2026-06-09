<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\ProfitabilityAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ProfitabilityAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_profitability_ranking_by_contribution_margin(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 100000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 40000);

        $ranking = app(ProfitabilityAnalyticsService::class)->getProfitabilityRanking((int) $outlet->id);

        $this->assertNotEmpty($ranking);
        $this->assertSame((string) $menu['menuId'], $ranking[0]['menuItemId']);
        $this->assertSame(60.0, $ranking[0]['marginPercent']);
    }
}
