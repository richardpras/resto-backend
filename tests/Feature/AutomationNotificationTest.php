<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Models\Modules\Menu\Domain\AutomationNotification;
use App\Modules\Menu\Services\NotificationDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class AutomationNotificationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_dispatches_database_and_skips_external_channels(): void
    {
        $outlet = $this->createValuationOutlet();
        $alert = AutomationAlert::query()->create([
            'outlet_id' => $outlet->id,
            'alert_type' => 'food_cost',
            'severity' => 'warning',
            'title' => 'Food cost exceeds threshold',
            'description' => 'Average food cost 45.00% exceeds threshold 40.00%.',
            'payload_json' => ['averageFoodCostPercent' => 45.0, 'threshold' => 40.0],
            'status' => 'open',
            'triggered_at' => now(),
        ]);

        $notifications = app(NotificationDispatchService::class)->dispatch(
            $alert,
            ['database', 'email', 'webhook'],
        );

        $this->assertCount(3, $notifications);
        $this->assertSame(3, AutomationNotification::query()->where('automation_alert_id', $alert->id)->count());
        $this->assertSame(1, AutomationNotification::query()
            ->where('automation_alert_id', $alert->id)
            ->where('channel', AutomationNotification::CHANNEL_DATABASE)
            ->where('status', 'sent')
            ->count());
        $this->assertSame(2, AutomationNotification::query()
            ->where('automation_alert_id', $alert->id)
            ->where('status', 'skipped')
            ->count());
    }
}
