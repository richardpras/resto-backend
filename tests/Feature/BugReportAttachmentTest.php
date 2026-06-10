<?php

namespace Tests\Feature;

use App\Models\Modules\System\Domain\BugReport;
use App\Models\Modules\System\Domain\BugReportAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\Concerns\BugReportTestFixture;
use Tests\TestCase;

class BugReportAttachmentTest extends TestCase
{
    use BugReportTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Storage::fake('local');
    }

    public function test_screenshot_stored_on_private_disk(): void
    {
        $outlet = $this->createBugReportOutlet();
        $this->actingAsBugReporter($outlet);

        $this->postJson('/api/v1/bug-reports', [
            'outletId' => (int) $outlet->id,
            'title' => 'UI glitch',
            'message' => 'Layout broken on dashboard.',
            'screenshot' => UploadedFile::fake()->image('capture.webp', 800, 600),
        ])->assertCreated();

        $attachment = BugReportAttachment::query()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->file_path);
        $this->assertSame('image/webp', $attachment->file_type);
    }

    public function test_admin_can_download_attachment(): void
    {
        $outlet = $this->createBugReportOutlet();
        $this->actingAsBugReporter($outlet);

        $create = $this->postJson('/api/v1/bug-reports', [
            'outletId' => (int) $outlet->id,
            'title' => 'Screenshot test',
            'message' => 'See attachment.',
            'screenshot' => UploadedFile::fake()->image('screen.png'),
        ])->assertCreated();

        $reportId = (int) $create->json('data.id');
        $attachmentId = (int) BugReportAttachment::query()->where('bug_report_id', $reportId)->value('id');

        $admin = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($admin);

        $this->get('/api/v1/bug-reports/'.$reportId.'/attachments/'.$attachmentId)
            ->assertOk();
    }

    public function test_rejects_oversized_screenshot(): void
    {
        $outlet = $this->createBugReportOutlet();
        $this->actingAsBugReporter($outlet);

        $this->postJson('/api/v1/bug-reports', [
            'outletId' => (int) $outlet->id,
            'title' => 'Too big',
            'message' => 'File too large.',
            'screenshot' => UploadedFile::fake()->create('big.png', 6000, 'image/png'),
        ])->assertStatus(422);
    }
}
