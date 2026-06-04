<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyNotification;
use App\Modules\LoyaltyEngine\Services\LoyaltyNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\LoyaltyNotificationTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyNotificationAnalyticsTest extends TestCase
{
    use LoyaltyNotificationTestFixtures;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_analytics_aggregation(): void
    {
        $admin = $this->actingAsNotificationManager();
        $outlet = $this->createNotificationOutlet('analytics');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createNotificationMember((int) $outlet->id, 'Analytics Member');

        $this->dispatchPointEarned((int) $outlet->id, (int) $member->id, 10);
        $this->dispatchPointEarned((int) $outlet->id, (int) $member->id, 20);

        app(LoyaltyNotificationService::class)->dispatchPointsRedeemed((int) $outlet->id, (int) $member->id, 5);

        $this->getJson('/api/v1/loyalty-engine/analytics?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.notificationsCount', 3)
            ->assertJsonPath('data.sentNotifications', 3)
            ->assertJsonPath('data.failedNotifications', 0);

        $summary = collect($this->getJson('/api/v1/loyalty-engine/analytics?outletId='.$outlet->id)
            ->json('data.notificationSummary'));

        $this->assertSame(2, (int) $summary->firstWhere('eventType', LoyaltyNotification::EVENT_POINT_EARNED)['count']);
        $this->assertSame(1, (int) $summary->firstWhere('eventType', LoyaltyNotification::EVENT_POINT_REDEEMED)['count']);
    }
}
