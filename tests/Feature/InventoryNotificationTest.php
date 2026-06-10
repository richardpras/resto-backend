<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\Adapters\InventoryNotificationAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryNotificationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class InventoryNotificationTest extends TestCase
{
    use InventoryNotificationFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_critical_stock_persists_notification(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createInventoryOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->dispatchCriticalAlert($outlet, 15, 'Chicken Breast', 2.5, 5.0);

        $this->assertDatabaseHas('user_notifications', [
            'outlet_id' => (int) $outlet->id,
            'source_module' => UserNotification::MODULE_INVENTORY,
            'source_type' => InventoryNotificationAdapter::TYPE_CRITICAL_STOCK,
            'source_id' => 'inventory-item-15',
            'severity' => UserNotification::SEVERITY_WARNING,
        ]);
    }

    public function test_out_of_stock_uses_high_domain_severity(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createInventoryOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->dispatchCriticalAlert($outlet, 15, 'Chicken Breast', 0, 5.0);

        $notification = UserNotification::query()
            ->where('source_type', InventoryNotificationAdapter::TYPE_OUT_OF_STOCK)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('inventory-item-15-outofstock', $notification->source_id);
        $this->assertSame('high', $notification->metadata['domainSeverity'] ?? null);
    }

    public function test_negative_stock_uses_critical_severity(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createInventoryOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->dispatchCriticalAlert($outlet, 15, 'Chicken Breast', -1.5, 5.0);

        $this->assertDatabaseHas('user_notifications', [
            'source_type' => InventoryNotificationAdapter::TYPE_NEGATIVE_STOCK,
            'severity' => UserNotification::SEVERITY_CRITICAL,
            'source_id' => 'inventory-item-15-negative',
        ]);
    }

    public function test_variance_detected_notification(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createInventoryOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        app(InventoryNotificationAdapter::class)->notifyVarianceDetected((int) $outlet->id, 250.0, 'variance');

        $this->assertDatabaseHas('user_notifications', [
            'outlet_id' => (int) $outlet->id,
            'source_type' => InventoryNotificationAdapter::TYPE_VARIANCE_DETECTED,
            'severity' => UserNotification::SEVERITY_WARNING,
            'action_url' => '/inventory?tab=valuation',
        ]);
    }
}
