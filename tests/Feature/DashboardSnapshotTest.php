<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\DashboardSnapshot;
use App\Modules\Menu\Services\DashboardSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class DashboardSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_dashboard_snapshot_is_idempotent(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);

        $service = app(DashboardSnapshotService::class);
        $date = now()->toDateString();
        $first = $service->createSnapshot((int) $outlet->id, $date);
        $second = $service->createSnapshot((int) $outlet->id, $date);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DashboardSnapshot::query()->where('outlet_id', $outlet->id)->count());
    }

    public function test_historical_dashboard_snapshots_are_never_overwritten(): void
    {
        $outlet = $this->createValuationOutlet();
        $date = now()->toDateString();

        DashboardSnapshot::query()->create([
            'snapshot_date' => $date,
            'outlet_id' => $outlet->id,
            'total_revenue' => 999999,
            'food_cost_percent' => 12,
            'average_margin_percent' => 55,
            'star_count' => 9,
            'puzzle_count' => 1,
            'plowhorse_count' => 2,
            'dog_count' => 0,
            'active_alerts' => 0,
            'critical_alerts' => 0,
            'optimization_opportunities' => 5,
            'forecast_revenue' => 100000,
            'forecast_margin' => 40000,
            'inventory_value' => 50000,
            'health_score' => 95,
        ]);

        app(DashboardSnapshotService::class)->createSnapshot((int) $outlet->id, $date);

        $snapshot = DashboardSnapshot::query()->where('outlet_id', $outlet->id)->firstOrFail();
        $this->assertSame(999999.0, (float) $snapshot->total_revenue);
        $this->assertSame(95.0, (float) $snapshot->health_score);
    }
}
