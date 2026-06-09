<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuEngineeringSnapshot;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuEngineeringSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class HistoricalMatrixIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_historical_matrix_snapshots_are_never_overwritten(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 50000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);

        $date = now()->toDateString();
        MenuEngineeringSnapshot::query()->create([
            'snapshot_date' => $date,
            'outlet_id' => $outlet->id,
            'menu_item_id' => $menu['menuId'],
            'quantity_sold' => 42,
            'popularity_percent' => 99,
            'contribution_margin' => 40000,
            'margin_percent' => 80,
            'classification' => 'STAR',
        ]);

        app(MenuEngineeringSnapshotService::class)->createSnapshot((int) $outlet->id, $date);

        $snapshot = MenuEngineeringSnapshot::query()->where('menu_item_id', $menu['menuId'])->firstOrFail();
        $this->assertSame(42.0, (float) $snapshot->quantity_sold);
        $this->assertSame('STAR', $snapshot->classification);
    }
}
