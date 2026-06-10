<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\System\Domain\BugReport;
use App\Modules\Notifications\Services\Adapters\BugReportNotificationAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BugReportTestFixture;
use Tests\TestCase;

class BugReportNotificationTest extends TestCase
{
    use BugReportTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Storage::fake('local');
    }

    public function test_creation_notifies_settings_manage_users(): void
    {
        $outlet = $this->createBugReportOutlet();
        $admin = $this->createUserWithPermission('settings.manage', $outlet);
        $this->actingAsBugReporter($outlet);

        $this->postJson('/api/v1/bug-reports', [
            'outletId' => (int) $outlet->id,
            'title' => 'Notify admins',
            'message' => 'Please triage.',
        ])->assertCreated();

        $reportId = (int) BugReport::query()->value('id');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => (int) $admin->id,
            'outlet_id' => (int) $outlet->id,
            'source_module' => UserNotification::MODULE_SYSTEM,
            'source_type' => BugReportNotificationAdapter::TYPE_CREATED,
            'source_id' => (string) $reportId,
            'action_url' => '/system/bug-reports/'.$reportId,
        ]);
    }

    public function test_status_change_notifies_reporter(): void
    {
        $outlet = $this->createBugReportOutlet();
        $reporter = $this->actingAsBugReporter($outlet);

        $report = BugReport::query()->create([
            'outlet_id' => $outlet->id,
            'reporter_user_id' => $reporter->id,
            'title' => 'Status notify',
            'message' => 'Track me',
            'severity' => BugReport::SEVERITY_MEDIUM,
            'status' => BugReport::STATUS_OPEN,
        ]);

        $admin = $this->createUserWithPermission('settings.manage', $outlet);
        $this->actingAs($admin, 'api');

        $this->patchJson('/api/v1/bug-reports/'.$report->id, [
            'status' => BugReport::STATUS_FIXED,
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => (int) $reporter->id,
            'source_type' => BugReportNotificationAdapter::TYPE_STATUS_UPDATED,
        ]);
    }
}
