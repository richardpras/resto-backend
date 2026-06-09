<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuEngineeringMatrixService;
use App\Modules\Menu\Services\MenuEngineeringSnapshotService;
use App\Modules\Menu\Services\MenuEngineeringTrendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class MenuEngineeringAuditTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_menu_engineering_audit_events_are_recorded(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 1, menuPrice: 60000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 15000);

        $oid = (int) $outlet->id;
        app(MenuEngineeringMatrixService::class)->generateMatrix($oid);
        app(MenuEngineeringSnapshotService::class)->createSnapshot($oid);
        app(MenuEngineeringTrendService::class)->calculateTrend(
            $oid,
            now()->subWeek()->toDateString(),
            now()->toDateString(),
        );

        $events = PosEventLog::query()->pluck('event_type')->all();

        $this->assertContains('menu_engineering_generated', $events);
        $this->assertContains('menu_engineering_snapshot_created', $events);
        $this->assertContains('menu_engineering_trend_generated', $events);
    }
}
