<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\Adapters\MenuNotificationAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenuAutomationNotificationFixture;
use Tests\TestCase;

class MenuAutomationRecipientTest extends TestCase
{
    use MenuAutomationNotificationFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_analytics_inventory_and_purchase_managers_receive_notifications(): void
    {
        $outlet = $this->createMenuAutomationOutlet();
        $analyticsUser = $this->createUserWithPermission('analytics.view', $outlet);
        $inventoryUser = $this->createUserWithPermission('inventory.manage', $outlet);
        $purchaseUser = $this->createUserWithPermission('purchase.manage', $outlet);

        $this->dispatchMenuAutomationAlert($outlet);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => (int) $analyticsUser->id,
            'source_type' => MenuNotificationAdapter::TYPE_FOOD_COST,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => (int) $inventoryUser->id,
            'source_type' => MenuNotificationAdapter::TYPE_FOOD_COST,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => (int) $purchaseUser->id,
            'source_type' => MenuNotificationAdapter::TYPE_FOOD_COST,
        ]);

        $this->assertSame(
            3,
            UserNotification::query()
                ->where('outlet_id', (int) $outlet->id)
                ->where('source_type', MenuNotificationAdapter::TYPE_FOOD_COST)
                ->count(),
        );
    }

    public function test_severe_alerts_include_settings_managers(): void
    {
        $outlet = $this->createMenuAutomationOutlet();
        $settingsUser = $this->createUserWithPermission('settings.manage', $outlet);

        $this->dispatchMenuAutomationAlert($outlet, [
            'alert_type' => 'dead_stock',
            'title' => 'Dead stock detected',
            'description' => 'Dead stock summary.',
            'payload_json' => [
                'ingredients' => [['ingredientId' => 12]],
                'dedupeKey' => 'dead_stock:30',
            ],
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => (int) $settingsUser->id,
            'source_type' => MenuNotificationAdapter::TYPE_DEAD_STOCK,
        ]);
    }
}
