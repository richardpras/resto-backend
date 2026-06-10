<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\Adapters\InventoryNotificationAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryNotificationFixture;
use Tests\TestCase;

class InventoryNotificationRecipientTest extends TestCase
{
    use InventoryNotificationFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_inventory_and_purchase_managers_receive_notifications(): void
    {
        $outlet = $this->createInventoryOutlet();
        $inventoryUser = $this->createUserWithPermission('inventory.manage', $outlet);
        $purchaseUser = $this->createUserWithPermission('purchase.manage', $outlet);

        $this->dispatchCriticalAlert($outlet, 20, 'Rice', 1.0, 10.0);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => (int) $inventoryUser->id,
            'source_type' => InventoryNotificationAdapter::TYPE_CRITICAL_STOCK,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => (int) $purchaseUser->id,
            'source_type' => InventoryNotificationAdapter::TYPE_CRITICAL_STOCK,
        ]);

        $this->assertSame(
            2,
            UserNotification::query()
                ->where('outlet_id', (int) $outlet->id)
                ->where('source_type', InventoryNotificationAdapter::TYPE_CRITICAL_STOCK)
                ->count(),
        );
    }
}
