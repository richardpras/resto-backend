<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ProfitabilityApiTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_menu_profitability_api_endpoints(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 100000);

        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 40000);

        $q = '?outletId='.$outlet->id;

        $this->getJson('/api/v1/menu-profitability/menu-items/'.$menu['menuId'].$q)
            ->assertOk()
            ->assertJsonPath('data.margin', 60000)
            ->assertJsonPath('data.marginPercent', 60)
            ->assertJsonPath('data.classification', 'PREMIUM');

        $this->getJson('/api/v1/menu-profitability/menu-items/'.$menu['menuId'].'/history'.$q)
            ->assertOk()
            ->assertJsonStructure(['data' => ['currentCost', 'currentMargin', 'comparisons']]);

        $this->postJson('/api/v1/menu-profitability/menu-items/'.$menu['menuId'].'/simulate'.$q, [
            'proposedPrice' => 120000,
        ])
            ->assertOk()
            ->assertJsonPath('data.proposedMargin', 80000)
            ->assertJsonPath('data.proposedMarginPercent', 66.6667);
    }
}
