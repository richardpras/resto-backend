<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuEngineeringSnapshot;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuEngineeringSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class MenuEngineeringSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_daily_snapshot_is_idempotent_per_menu_item(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 50000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 20000);

        $service = app(MenuEngineeringSnapshotService::class);
        $date = now()->toDateString();
        $first = $service->createSnapshot((int) $outlet->id, $date);
        $second = $service->createSnapshot((int) $outlet->id, $date);

        $this->assertSame($first->first()->id, $second->first()->id);
        $this->assertSame(1, MenuEngineeringSnapshot::query()->where('outlet_id', $outlet->id)->count());
    }
}
