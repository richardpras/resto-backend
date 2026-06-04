<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomationLog;
use App\Modules\LoyaltyEngine\Services\LoyaltyAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\LoyaltyAutomationTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyAutomationAnalyticsTest extends TestCase
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

    public function test_analytics_includes_automation_aggregation(): void
    {
        $admin = $this->actingAsAutomationManager();
        $outlet = $this->createAutomationOutlet('analytics');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $memberA = $this->createAutomationMember((int) $outlet->id, 'Analytics A');
        $memberB = $this->createAutomationMember((int) $outlet->id, 'Analytics B');

        $automationId = $this->createAutomationViaApi(
            (int) $outlet->id,
            'ANALYTICS_NOTIFY',
            LoyaltyAutomation::TRIGGER_TIER_UPGRADED,
            LoyaltyAutomation::ACTION_SEND_NOTIFICATION,
            [],
            ['title' => 'Tier up', 'content' => 'Congrats'],
        );

        $this->createAutomationViaApi(
            (int) $outlet->id,
            'INACTIVE_ONLY',
            LoyaltyAutomation::TRIGGER_INACTIVE_MEMBER,
            LoyaltyAutomation::ACTION_SEND_NOTIFICATION,
            ['daysInactive' => 30],
            ['title' => 'Inactive', 'content' => 'Come back'],
        );

        $service = app(LoyaltyAutomationService::class);
        $service->processEvent((int) $outlet->id, (int) $memberA->id, LoyaltyAutomation::TRIGGER_TIER_UPGRADED);
        $service->processEvent((int) $outlet->id, (int) $memberB->id, LoyaltyAutomation::TRIGGER_TIER_UPGRADED);

        LoyaltyAutomationLog::query()->create([
            'automation_id' => $automationId,
            'member_id' => $memberA->id,
            'trigger_type' => LoyaltyAutomation::TRIGGER_TIER_UPGRADED,
            'action_type' => LoyaltyAutomation::ACTION_SEND_NOTIFICATION,
            'status' => LoyaltyAutomationLog::STATUS_FAILED,
            'result_json' => ['message' => 'forced failure'],
            'executed_at' => now(),
        ]);

        $this->getJson("/api/v1/loyalty-engine/analytics?outletId={$outlet->id}")
            ->assertOk()
            ->assertJsonPath('data.automationsCount', 2)
            ->assertJsonPath('data.activeAutomations', 2)
            ->assertJsonPath('data.automationExecutions', 3)
            ->assertJsonPath('data.automationSuccess', 2)
            ->assertJsonPath('data.automationFailed', 1)
            ->assertJsonPath('data.automationSummary.0.automation', 'ANALYTICS_NOTIFY Automation')
            ->assertJsonPath('data.automationSummary.0.executions', 2);
    }
}
