<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Modules\Menu\Services\DashboardService;
use App\Modules\Menu\Services\DashboardSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class DashboardAuditTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_dashboard_audit_events_are_recorded(): void
    {
        $outlet = $this->createValuationOutlet();
        $oid = (int) $outlet->id;
        $this->seedMenuWithRecipe($oid, (int) $this->createIngredientForOutlet($oid)->id);

        app(DashboardService::class)->recordView($oid);
        app(DashboardService::class)->getSummary($oid);
        app(DashboardSnapshotService::class)->createSnapshot($oid);

        $events = PosEventLog::query()->pluck('event_type')->all();

        $this->assertContains('dashboard_viewed', $events);
        $this->assertContains('dashboard_generated', $events);
        $this->assertContains('health_score_generated', $events);
        $this->assertContains('dashboard_snapshot_created', $events);
    }
}
