<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\LoyaltyNotificationTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyNotificationTest extends TestCase
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

    public function test_notification_created_with_default_template(): void
    {
        $this->actingAsNotificationManager();
        $outlet = $this->createNotificationOutlet('create');
        $member = $this->createNotificationMember((int) $outlet->id, 'Alice Notify');

        $this->dispatchPointEarned((int) $outlet->id, (int) $member->id, 50);

        $this->assertDatabaseHas('loyalty_notifications', [
            'outlet_id' => $outlet->id,
            'member_id' => $member->id,
            'event_type' => LoyaltyNotification::EVENT_POINT_EARNED,
            'channel' => LoyaltyNotification::CHANNEL_IN_APP,
            'status' => LoyaltyNotification::STATUS_SENT,
        ]);
    }

    public function test_template_rendering_and_variable_replacement(): void
    {
        $this->actingAsNotificationManager();
        $outlet = $this->createNotificationOutlet('template');
        $member = $this->createNotificationMember((int) $outlet->id, 'Bob Template');
        $program = $this->seedSpendProgram((int) $outlet->id);
        $this->grantMemberPoints((int) $member->id, (int) $program->id, 100);

        $this->createNotificationTemplate(
            (int) $outlet->id,
            LoyaltyNotification::EVENT_POINT_EARNED,
            LoyaltyNotification::CHANNEL_IN_APP,
            'Earned {{points}} for {{member_name}}',
            'Balance now {{current_balance}}',
        );

        $this->dispatchPointEarned((int) $outlet->id, (int) $member->id, 25);

        $notification = LoyaltyNotification::query()
            ->where('member_id', $member->id)
            ->where('channel', LoyaltyNotification::CHANNEL_IN_APP)
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('Earned 25 for Bob Template', $notification->title);
        $this->assertSame('Balance now 100', $notification->content);
    }

    public function test_profile_includes_notifications(): void
    {
        $admin = $this->actingAsNotificationManager();
        $outlet = $this->createNotificationOutlet('profile');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createNotificationMember((int) $outlet->id, 'Carol Profile');

        $this->dispatchPointEarned((int) $outlet->id, (int) $member->id, 10);

        $this->getJson('/api/v1/members/'.$member->id.'/profile?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.notifications.0.eventType', LoyaltyNotification::EVENT_POINT_EARNED)
            ->assertJsonPath('data.notifications.0.status', LoyaltyNotification::STATUS_SENT)
            ->assertJsonPath('data.notifications.0.title', 'You earned 10 points');
    }

    public function test_member_notifications_api_lists_records(): void
    {
        $admin = $this->actingAsNotificationManager();
        $outlet = $this->createNotificationOutlet('list');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createNotificationMember((int) $outlet->id, 'Dave List');

        $this->dispatchPointEarned((int) $outlet->id, (int) $member->id, 5);

        $this->getJson('/api/v1/members/'.$member->id.'/notifications?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.eventType', LoyaltyNotification::EVENT_POINT_EARNED);
    }
}
