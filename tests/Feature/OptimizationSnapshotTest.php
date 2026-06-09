<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuOptimizationSnapshot;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuOptimizationSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class OptimizationSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_daily_optimization_snapshot_is_idempotent(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 40000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 12000);

        $service = app(MenuOptimizationSnapshotService::class);
        $date = now()->toDateString();
        $first = $service->createSnapshot((int) $outlet->id, $date);
        $second = $service->createSnapshot((int) $outlet->id, $date);

        $this->assertGreaterThan(0, $first->count());
        $this->assertSame($first->count(), $second->count());

        $engineering = MenuOptimizationSnapshot::query()
            ->where('menu_item_id', $menu['menuId'])
            ->where('recommendation_type', MenuOptimizationSnapshot::TYPE_ENGINEERING)
            ->firstOrFail();

        $this->assertSame($engineering->id, MenuOptimizationSnapshot::query()
            ->where('menu_item_id', $menu['menuId'])
            ->where('recommendation_type', MenuOptimizationSnapshot::TYPE_ENGINEERING)
            ->value('id'));
    }
}
