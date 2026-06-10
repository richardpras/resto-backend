<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\Adapters\InventoryNotificationAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryNotificationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class InventoryNotificationDuplicateTest extends TestCase
{
    use InventoryNotificationFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_repeated_critical_alert_creates_one_notification_per_user(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createInventoryOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->dispatchCriticalAlert($outlet, 15, 'Chicken Breast', 2.0, 5.0);
        $this->dispatchCriticalAlert($outlet, 15, 'Chicken Breast', 1.5, 5.0);

        $this->assertSame(
            1,
            UserNotification::query()
                ->where('user_id', (int) $user->id)
                ->where('source_type', InventoryNotificationAdapter::TYPE_CRITICAL_STOCK)
                ->where('source_id', 'inventory-item-15')
                ->count(),
        );
    }
}
