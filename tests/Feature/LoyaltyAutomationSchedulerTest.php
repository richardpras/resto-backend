<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomationLog;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\LoyaltyAutomationTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyAutomationSchedulerTest extends TestCase
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

    public function test_birthday_automation_runs_via_scheduler(): void
    {
        $admin = $this->actingAsAutomationManager();
        $outlet = $this->createAutomationOutlet('birthday');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $voucher = $this->createAutomationVoucher((int) $outlet->id);
        $member = $this->createAutomationMember(
            (int) $outlet->id,
            'Birthday Member',
            now()->toDateString(),
        );

        $this->createAutomationViaApi(
            (int) $outlet->id,
            'BDAY',
            LoyaltyAutomation::TRIGGER_MEMBER_BIRTHDAY,
            LoyaltyAutomation::ACTION_ISSUE_VOUCHER,
            ['daysBefore' => 0],
            ['voucherId' => (int) $voucher->id],
        );

        Artisan::call('loyalty:process-automations');

        $this->assertDatabaseHas('loyalty_automation_logs', [
            'member_id' => $member->id,
            'trigger_type' => LoyaltyAutomation::TRIGGER_MEMBER_BIRTHDAY,
            'status' => LoyaltyAutomationLog::STATUS_SUCCESS,
        ]);

        $this->assertDatabaseHas('member_vouchers', [
            'member_id' => $member->id,
            'voucher_id' => $voucher->id,
            'status' => MemberVoucher::STATUS_ISSUED,
        ]);
    }

    public function test_inactive_member_automation_runs_via_scheduler(): void
    {
        $admin = $this->actingAsAutomationManager();
        $outlet = $this->createAutomationOutlet('inactive');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createAutomationMember((int) $outlet->id, 'Inactive Member');
        $this->makeMemberInactive($member, 30);

        $this->createAutomationViaApi(
            (int) $outlet->id,
            'INACTIVE',
            LoyaltyAutomation::TRIGGER_INACTIVE_MEMBER,
            LoyaltyAutomation::ACTION_SEND_NOTIFICATION,
            ['daysInactive' => 30],
            ['title' => 'We miss you', 'content' => 'Come back soon'],
        );

        Artisan::call('loyalty:process-automations');

        $this->assertDatabaseHas('loyalty_automation_logs', [
            'member_id' => $member->id,
            'trigger_type' => LoyaltyAutomation::TRIGGER_INACTIVE_MEMBER,
            'status' => LoyaltyAutomationLog::STATUS_SUCCESS,
        ]);
    }

    public function test_scheduler_is_idempotent_for_same_day_success(): void
    {
        $admin = $this->actingAsAutomationManager();
        $outlet = $this->createAutomationOutlet('idempotent');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createAutomationMember(
            (int) $outlet->id,
            'Repeat Birthday',
            now()->toDateString(),
        );
        $voucher = $this->createAutomationVoucher((int) $outlet->id);

        $this->createAutomationViaApi(
            (int) $outlet->id,
            'BDAY_ONCE',
            LoyaltyAutomation::TRIGGER_MEMBER_BIRTHDAY,
            LoyaltyAutomation::ACTION_ISSUE_VOUCHER,
            ['daysBefore' => 0],
            ['voucherId' => (int) $voucher->id],
        );

        Artisan::call('loyalty:process-automations');
        Artisan::call('loyalty:process-automations');

        $successLogs = LoyaltyAutomationLog::query()
            ->where('member_id', $member->id)
            ->where('status', LoyaltyAutomationLog::STATUS_SUCCESS)
            ->whereDate('executed_at', today())
            ->count();

        self::assertSame(1, $successLogs);

        $issuedCount = MemberVoucher::query()
            ->where('member_id', $member->id)
            ->where('voucher_id', $voucher->id)
            ->count();

        self::assertSame(1, $issuedCount);
    }
}
