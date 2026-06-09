<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuAnalyticsSnapshot;
use App\Modules\Menu\Jobs\CreateDailyAnalyticsSnapshotJob;
use App\Modules\Menu\Jobs\CreateDailyAutomationSnapshotJob;
use App\Modules\Menu\Jobs\CreateDailyEngineeringSnapshotJob;
use App\Modules\Menu\Jobs\CreateDailyForecastSnapshotJob;
use App\Modules\Menu\Jobs\CreateDailyOptimizationSnapshotJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class QueueJobTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_daily_analytics_snapshot_job_is_idempotent(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);
        $date = now()->toDateString();

        CreateDailyAnalyticsSnapshotJob::dispatchSync((int) $outlet->id, $date);
        CreateDailyAnalyticsSnapshotJob::dispatchSync((int) $outlet->id, $date);

        $this->assertSame(1, MenuAnalyticsSnapshot::query()->where('outlet_id', $outlet->id)->count());
    }

    public function test_queue_jobs_can_be_dispatched_without_error(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);
        $oid = (int) $outlet->id;
        $date = now()->toDateString();

        CreateDailyEngineeringSnapshotJob::dispatchSync($oid, $date);
        CreateDailyOptimizationSnapshotJob::dispatchSync($oid, $date);
        CreateDailyForecastSnapshotJob::dispatchSync($oid, $date);
        CreateDailyAutomationSnapshotJob::dispatchSync($oid, $date);

        $this->assertTrue(true);
    }
}
