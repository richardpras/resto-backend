<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ContributionMarginTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_contribution_margin_equals_selling_price_minus_variable_cost(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 2, menuPrice: 80000);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 15000);

        $service = app(MenuProfitabilityService::class);
        $result = $service->calculateProfitability($menu['menuId'], (int) $outlet->id);

        $this->assertSame(30000.0, $result['cost']);
        $this->assertSame(50000.0, $result['contributionMargin']);
        $this->assertSame($result['margin'], $result['contributionMargin']);
    }
}
