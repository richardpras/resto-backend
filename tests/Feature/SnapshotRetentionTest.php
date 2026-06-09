<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuAnalyticsSnapshot;
use App\Modules\Menu\Services\SnapshotRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class SnapshotRetentionTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_archives_old_snapshots_without_deleting_source(): void
    {
        $outlet = $this->createValuationOutlet();
        $oldDate = now()->subMonths(25)->toDateString();

        $snapshot = MenuAnalyticsSnapshot::query()->create([
            'snapshot_date' => $oldDate,
            'outlet_id' => $outlet->id,
            'average_food_cost_percent' => 30,
            'average_margin_percent' => 50,
            'inventory_value' => 1000,
            'daily_cogs' => 200,
            'production_efficiency_percent' => 80,
            'total_sales' => 5000,
            'total_orders' => 10,
        ]);

        $counts = app(SnapshotRetentionService::class)->archiveExpiredSnapshots((int) $outlet->id);

        $this->assertSame(1, $counts['analytics']);
        $this->assertDatabaseHas('analytics_snapshot_archives', [
            'source_snapshot_id' => $snapshot->id,
            'outlet_id' => $outlet->id,
        ]);
        $this->assertDatabaseHas('menu_analytics_snapshots', [
            'id' => $snapshot->id,
            'total_sales' => 5000,
        ]);
        $this->assertSame(1, DB::table('menu_analytics_snapshots')->count());
    }
}
