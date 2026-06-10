<?php

namespace Tests\Feature;

use App\Models\Modules\System\Domain\BugReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Concerns\BugReportTestFixture;
use Tests\TestCase;

class BugReportStatusUpdateTest extends TestCase
{
    use BugReportTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_admin_can_update_status_assign_and_comment(): void
    {
        $outlet = $this->createBugReportOutlet();
        $reporter = $this->actingAsBugReporter($outlet);

        $report = BugReport::query()->create([
            'outlet_id' => $outlet->id,
            'reporter_user_id' => $reporter->id,
            'title' => 'Workflow test',
            'message' => 'Status transitions',
            'severity' => BugReport::SEVERITY_LOW,
            'status' => BugReport::STATUS_OPEN,
        ]);

        $admin = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($admin);

        $this->patchJson('/api/v1/bug-reports/'.$report->id, [
            'status' => BugReport::STATUS_INVESTIGATING,
            'assignedToUserId' => (int) $admin->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', BugReport::STATUS_INVESTIGATING)
            ->assertJsonPath('data.assignedToUserId', (int) $admin->id);

        $this->postJson('/api/v1/bug-reports/'.$report->id.'/comments', [
            'comment' => 'Reproduced locally.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.comment', 'Reproduced locally.');

        $this->assertDatabaseHas('bug_report_comments', [
            'bug_report_id' => (int) $report->id,
            'user_id' => (int) $admin->id,
        ]);
    }
}
