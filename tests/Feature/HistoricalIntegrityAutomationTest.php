<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\AutomationSnapshot;
use App\Modules\Menu\Services\AutomationSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class HistoricalIntegrityAutomationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_historical_automation_snapshots_are_never_overwritten(): void
    {
        $outlet = $this->createValuationOutlet();
        $date = now()->toDateString();

        AutomationSnapshot::query()->create([
            'snapshot_date' => $date,
            'outlet_id' => $outlet->id,
            'alerts_generated' => 99,
            'critical_alerts' => 5,
            'warnings' => 10,
            'recommendations_generated' => 20,
            'resolved_alerts' => 3,
        ]);

        app(AutomationSnapshotService::class)->createSnapshot((int) $outlet->id, $date);

        $snapshot = AutomationSnapshot::query()->where('outlet_id', $outlet->id)->firstOrFail();
        $this->assertSame(99, $snapshot->alerts_generated);
        $this->assertSame(5, $snapshot->critical_alerts);
    }
}
