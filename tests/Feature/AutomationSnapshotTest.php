<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\AutomationSnapshot;
use App\Modules\Menu\Services\AutomationSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class AutomationSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_automation_snapshot_is_idempotent(): void
    {
        $outlet = $this->createValuationOutlet();
        $date = now()->toDateString();
        $service = app(AutomationSnapshotService::class);

        $first = $service->createSnapshot((int) $outlet->id, $date);
        $second = $service->createSnapshot((int) $outlet->id, $date);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AutomationSnapshot::query()->where('outlet_id', $outlet->id)->count());
    }
}
