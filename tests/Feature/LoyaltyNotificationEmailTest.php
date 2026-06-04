<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\LoyaltyNotificationTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyNotificationEmailTest extends TestCase
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

    public function test_skips_email_when_brevo_not_configured(): void
    {
        $this->actingAsNotificationManager();
        $outlet = $this->createNotificationOutlet('skip');
        $member = $this->createNotificationMember((int) $outlet->id, 'Email Skip', 'member@example.com');

        Http::fake();

        $this->dispatchPointEarned((int) $outlet->id, (int) $member->id, 15);

        Http::assertNothingSent();
        $this->assertDatabaseMissing('loyalty_notifications', [
            'member_id' => $member->id,
            'channel' => LoyaltyNotification::CHANNEL_EMAIL,
        ]);
        $this->assertDatabaseHas('loyalty_notifications', [
            'member_id' => $member->id,
            'channel' => LoyaltyNotification::CHANNEL_IN_APP,
            'status' => LoyaltyNotification::STATUS_SENT,
        ]);
    }

    public function test_email_path_works_when_brevo_configured(): void
    {
        $this->actingAsNotificationManager();
        $outlet = $this->createNotificationOutlet('send');
        $member = $this->createNotificationMember((int) $outlet->id, 'Email Send', 'member@example.com');
        $this->configureOutletBrevo((int) $outlet->id);
        $this->fakeSuccessfulBrevo();

        $this->dispatchPointEarned((int) $outlet->id, (int) $member->id, 20);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('loyalty_notifications', [
            'member_id' => $member->id,
            'channel' => LoyaltyNotification::CHANNEL_EMAIL,
            'status' => LoyaltyNotification::STATUS_SENT,
        ]);
    }

    public function test_email_failure_does_not_break_loyalty_redeem_flow(): void
    {
        $admin = $this->actingAsNotificationManager();
        $outlet = $this->createNotificationOutlet('fail');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createNotificationMember((int) $outlet->id, 'Redeem Safe', 'member@example.com');
        $program = $this->seedSpendProgram((int) $outlet->id);
        $this->grantMemberPoints((int) $member->id, (int) $program->id, 200);
        $this->configureOutletBrevo((int) $outlet->id);

        Http::fake([
            'api.brevo.com/*' => Http::response(['message' => 'error'], 500),
        ]);

        $this->postJson('/api/v1/members/'.$member->id.'/redeem', [
            'outletId' => $outlet->id,
            'points' => 50,
        ])
            ->assertOk()
            ->assertJsonPath('data.redeemedPoints', 50);

        $this->assertDatabaseHas('loyalty_notifications', [
            'member_id' => $member->id,
            'event_type' => LoyaltyNotification::EVENT_POINT_REDEEMED,
            'channel' => LoyaltyNotification::CHANNEL_IN_APP,
            'status' => LoyaltyNotification::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('loyalty_notifications', [
            'member_id' => $member->id,
            'channel' => LoyaltyNotification::CHANNEL_EMAIL,
            'status' => LoyaltyNotification::STATUS_FAILED,
        ]);
    }
}
