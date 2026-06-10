<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\System\Domain\BugReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Concerns\BugReportTestFixture;
use Tests\TestCase;

class BugReportAuditTest extends TestCase
{
    use BugReportTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_create_emits_audit_event(): void
    {
        $outlet = $this->createBugReportOutlet();
        $this->actingAsBugReporter($outlet);

        $this->postJson('/api/v1/bug-reports', [
            'outletId' => (int) $outlet->id,
            'title' => 'Audit trail',
            'message' => 'Should log event',
        ])->assertCreated();

        $reportId = (int) BugReport::query()->value('id');

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'bug_report.created',
            'entity_type' => 'bug_report',
            'entity_id' => $reportId,
            'outlet_id' => (int) $outlet->id,
        ]);
    }

    public function test_status_change_emits_audit_event(): void
    {
        $outlet = $this->createBugReportOutlet();
        $reporter = $this->actingAsBugReporter($outlet);

        $report = BugReport::query()->create([
            'outlet_id' => $outlet->id,
            'reporter_user_id' => $reporter->id,
            'title' => 'Close me',
            'message' => 'Audit close',
            'severity' => BugReport::SEVERITY_LOW,
            'status' => BugReport::STATUS_OPEN,
        ]);

        $admin = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($admin);

        $this->patchJson('/api/v1/bug-reports/'.$report->id, [
            'status' => BugReport::STATUS_CLOSED,
        ])->assertOk();

        $this->assertTrue(
            PosEventLog::query()
                ->where('entity_type', 'bug_report')
                ->where('entity_id', (int) $report->id)
                ->where('event_type', 'bug_report.closed')
                ->exists()
        );
    }

    public function test_comment_emits_audit_event(): void
    {
        $outlet = $this->createBugReportOutlet();
        $reporter = $this->actingAsBugReporter($outlet);

        $report = BugReport::query()->create([
            'outlet_id' => $outlet->id,
            'reporter_user_id' => $reporter->id,
            'title' => 'Comment audit',
            'message' => 'Notes',
            'severity' => BugReport::SEVERITY_LOW,
            'status' => BugReport::STATUS_OPEN,
        ]);

        $admin = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($admin);

        $this->postJson('/api/v1/bug-reports/'.$report->id.'/comments', [
            'comment' => 'Investigating now.',
        ])->assertCreated();

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'bug_report.commented',
            'entity_type' => 'bug_report',
            'entity_id' => (int) $report->id,
        ]);
    }
}
