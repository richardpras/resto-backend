<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuAnalyticsSnapshot;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\AnalyticsSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class AnalyticsSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_daily_snapshot_is_idempotent_per_outlet(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 50000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 20000);

        $service = app(AnalyticsSnapshotService::class);
        $first = $service->createDailySnapshot((int) $outlet->id, now()->toDateString());
        $second = $service->createDailySnapshot((int) $outlet->id, now()->toDateString());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MenuAnalyticsSnapshot::query()->where('outlet_id', $outlet->id)->count());
    }
}
