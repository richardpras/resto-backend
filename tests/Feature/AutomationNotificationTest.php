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

    public function test_dispatches_database_and_email_notifications(): void
    {
        $outlet = $this->createValuationOutlet();
        $alert = AutomationAlert::query()->create([
            'outlet_id' => $outlet->id,
            'alert_type' => 'test',
            'severity' => 'critical',
            'title' => 'Test Alert',
            'description' => 'Test description',
            'status' => 'open',
            'triggered_at' => now(),
        ]);

        $notifications = app(NotificationDispatchService::class)->dispatch(
            $alert,
            ['database', 'email', 'webhook'],
        );

        $this->assertCount(3, $notifications);
        $this->assertSame(3, AutomationNotification::query()->where('automation_alert_id', $alert->id)->count());
    }
}
