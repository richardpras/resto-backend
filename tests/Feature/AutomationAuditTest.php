<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Modules\Menu\Services\AlertRuleService;
use App\Modules\Menu\Services\AutomationSnapshotService;
use App\Modules\Menu\Services\MenuAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class AutomationAuditTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_automation_audit_events_are_recorded(): void
    {
        $outlet = $this->createValuationOutlet();
        $oid = (int) $outlet->id;

        $rule = app(AlertRuleService::class)->createRule($oid, [
            'ruleName' => 'Custom Rule',
            'ruleType' => 'custom_test',
            'thresholdValue' => 10,
            'severity' => 'warning',
        ]);
        app(AlertRuleService::class)->updateRule((int) $rule->id, $oid, ['thresholdValue' => 15]);
        app(MenuAutomationService::class)->runAutomation($oid);
        app(AutomationSnapshotService::class)->createSnapshot($oid);

        $events = PosEventLog::query()->pluck('event_type')->all();

        $this->assertContains('automation_rule_created', $events);
        $this->assertContains('automation_rule_updated', $events);
        $this->assertContains('automation_snapshot_created', $events);
    }
}
