<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\Adapters\MenuNotificationAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenuAutomationNotificationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class MenuAutomationDuplicateTest extends TestCase
{
    use MenuAutomationNotificationFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_repeated_dispatch_creates_one_notification_per_user(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createMenuAutomationOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->dispatchMenuAutomationAlert($outlet);
        $this->dispatchMenuAutomationAlert($outlet);

        $this->assertSame(
            1,
            UserNotification::query()
                ->where('user_id', (int) $user->id)
                ->where('source_type', MenuNotificationAdapter::TYPE_FOOD_COST)
                ->where('source_id', 'food-cost-outlet-'.(int) $outlet->id)
                ->count(),
        );
    }
}
