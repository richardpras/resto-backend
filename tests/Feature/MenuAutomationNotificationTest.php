<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\Adapters\MenuNotificationAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenuAutomationNotificationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class MenuAutomationNotificationTest extends TestCase
{
    use MenuAutomationNotificationFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_food_cost_alert_creates_notification(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createMenuAutomationOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->dispatchMenuAutomationAlert($outlet);

        $this->assertDatabaseHas('user_notifications', [
            'outlet_id' => (int) $outlet->id,
            'source_module' => UserNotification::MODULE_MENU_INTELLIGENCE,
            'source_type' => MenuNotificationAdapter::TYPE_FOOD_COST,
            'source_id' => 'food-cost-outlet-'.(int) $outlet->id,
            'severity' => UserNotification::SEVERITY_WARNING,
            'action_url' => '/dashboard/menu?tab=food-cost',
        ]);
    }

    public function test_dead_stock_uses_high_domain_severity(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createMenuAutomationOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->dispatchMenuAutomationAlert($outlet, [
            'alert_type' => 'dead_stock',
            'title' => 'Dead stock detected',
            'description' => '1 ingredients without movement for 30 days.',
            'payload_json' => [
                'ingredients' => [['ingredientId' => 8, 'name' => 'Flour']],
                'dedupeKey' => 'dead_stock:30',
            ],
        ]);

        $notification = UserNotification::query()
            ->where('source_type', MenuNotificationAdapter::TYPE_DEAD_STOCK)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('dead-stock-item-8', $notification->source_id);
        $this->assertSame(UserNotification::SEVERITY_WARNING, $notification->severity);
        $this->assertSame('high', $notification->metadata['domainSeverity'] ?? null);
        $this->assertSame('/dashboard/menu?tab=dead-stock', $notification->action_url);
    }

    public function test_margin_erosion_uses_menu_item_source_id(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createMenuAutomationOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->dispatchMenuAutomationAlert($outlet, [
            'alert_type' => 'margin_erosion',
            'title' => 'Margin erosion detected',
            'description' => 'Menu item Burger margin dropped 8.00%.',
            'payload_json' => [
                'menuItemId' => 15,
                'menuItemName' => 'Burger',
                'erosionPercent' => 8.0,
                'dedupeKey' => 'margin_erosion:15',
            ],
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'source_type' => MenuNotificationAdapter::TYPE_MARGIN_EROSION,
            'source_id' => 'margin-menu-15',
            'action_url' => '/dashboard/menu?tab=profitability',
        ]);
    }

    public function test_outlet_isolation(): void
    {
        $outletA = $this->createMenuAutomationOutlet('A');
        $outletB = $this->createMenuAutomationOutlet('B');
        $user = $this->createUserWithPermission('analytics.view', $outletA);

        $this->dispatchMenuAutomationAlert($outletB);

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => (int) $user->id,
            'outlet_id' => (int) $outletB->id,
        ]);
    }
}
