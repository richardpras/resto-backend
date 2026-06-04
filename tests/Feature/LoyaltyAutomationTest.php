<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomationLog;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Modules\LoyaltyEngine\Services\LoyaltyAutomationService;
use App\Modules\Members\Services\MemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\LoyaltyAutomationTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyAutomationTest extends TestCase
{
    use LoyaltyAutomationTestFixtures;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_member_created_trigger_executes_notification_action_and_logs(): void
    {
        $admin = $this->actingAsAutomationManager();
        $outlet = $this->createAutomationOutlet('created');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $this->createAutomationViaApi(
            (int) $outlet->id,
            'WELCOME',
            LoyaltyAutomation::TRIGGER_MEMBER_CREATED,
            LoyaltyAutomation::ACTION_SEND_NOTIFICATION,
            [],
            [
                'title' => 'Welcome {{member_name}}',
                'content' => 'Glad to have you',
            ],
        );

        $member = app(MemberService::class)->create($admin, [
            'outletId' => (int) $outlet->id,
            'fullName' => 'New Member',
            'phone' => '081234567890',
        ]);

        $this->assertDatabaseHas('loyalty_automation_logs', [
            'member_id' => $member->id,
            'trigger_type' => LoyaltyAutomation::TRIGGER_MEMBER_CREATED,
            'action_type' => LoyaltyAutomation::ACTION_SEND_NOTIFICATION,
            'status' => LoyaltyAutomationLog::STATUS_SUCCESS,
        ]);

        $this->assertDatabaseHas('loyalty_notifications', [
            'member_id' => $member->id,
            'title' => 'Welcome New Member',
            'content' => 'Glad to have you',
        ]);
    }

    public function test_issue_voucher_action_executes_on_event(): void
    {
        $admin = $this->actingAsAutomationManager();
        $outlet = $this->createAutomationOutlet('voucher');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createAutomationMember((int) $outlet->id, 'Voucher Member');
        $voucher = $this->createAutomationVoucher((int) $outlet->id);

        $this->createAutomationViaApi(
            (int) $outlet->id,
            'WELCOME_VOUCHER',
            LoyaltyAutomation::TRIGGER_MEMBER_CREATED,
            LoyaltyAutomation::ACTION_ISSUE_VOUCHER,
            [],
            ['voucherId' => (int) $voucher->id],
        );

        app(LoyaltyAutomationService::class)->processEvent(
            (int) $outlet->id,
            (int) $member->id,
            LoyaltyAutomation::TRIGGER_MEMBER_CREATED,
        );

        $this->assertDatabaseHas('loyalty_automation_logs', [
            'member_id' => $member->id,
            'status' => LoyaltyAutomationLog::STATUS_SUCCESS,
        ]);

        $this->assertDatabaseHas('member_vouchers', [
            'member_id' => $member->id,
            'voucher_id' => $voucher->id,
            'status' => MemberVoucher::STATUS_ISSUED,
        ]);
    }

    public function test_automation_crud_and_logs_api(): void
    {
        $admin = $this->actingAsAutomationManager();
        $outlet = $this->createAutomationOutlet('api');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createAutomationMember((int) $outlet->id, 'API Member');

        $automationId = $this->createAutomationViaApi(
            (int) $outlet->id,
            'API_NOTIFY',
            LoyaltyAutomation::TRIGGER_TIER_UPGRADED,
            LoyaltyAutomation::ACTION_SEND_NOTIFICATION,
            [],
            ['title' => 'Tier up', 'content' => 'Congrats'],
        );

        app(LoyaltyAutomationService::class)->processEvent(
            (int) $outlet->id,
            (int) $member->id,
            LoyaltyAutomation::TRIGGER_TIER_UPGRADED,
            ['tierName' => 'Gold'],
        );

        $this->getJson("/api/v1/loyalty-automations?outletId={$outlet->id}")
            ->assertOk()
            ->assertJsonPath('data.0.code', 'API_NOTIFY');

        $this->getJson("/api/v1/loyalty-automations/{$automationId}/logs")
            ->assertOk()
            ->assertJsonPath('data.0.status', LoyaltyAutomationLog::STATUS_SUCCESS);

        $this->patchJson("/api/v1/loyalty-automations/{$automationId}/activation", ['isActive' => false])
            ->assertOk()
            ->assertJsonPath('data.isActive', false);
    }
}
