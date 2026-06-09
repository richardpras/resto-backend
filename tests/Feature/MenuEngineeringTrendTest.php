<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuEngineeringSnapshot;
use App\Modules\Menu\Services\MenuEngineeringTrendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class MenuEngineeringTrendTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_detects_classification_movement_between_periods(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id);

        MenuEngineeringSnapshot::query()->create([
            'snapshot_date' => now()->subMonth()->toDateString(),
            'outlet_id' => $outlet->id,
            'menu_item_id' => $menu['menuId'],
            'quantity_sold' => 5,
            'popularity_percent' => 10,
            'contribution_margin' => 5000,
            'margin_percent' => 20,
            'classification' => 'DOG',
        ]);
        MenuEngineeringSnapshot::query()->create([
            'snapshot_date' => now()->toDateString(),
            'outlet_id' => $outlet->id,
            'menu_item_id' => $menu['menuId'],
            'quantity_sold' => 80,
            'popularity_percent' => 70,
            'contribution_margin' => 50000,
            'margin_percent' => 60,
            'classification' => 'STAR',
        ]);

        $trend = app(MenuEngineeringTrendService::class)->calculateTrend(
            (int) $outlet->id,
            now()->subMonth()->toDateString(),
            now()->toDateString(),
        );

        $this->assertSame('DOG → STAR', $trend['movements'][0]['movementType']);
    }
}
