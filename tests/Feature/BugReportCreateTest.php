<?php

namespace Tests\Feature;

use App\Models\Modules\System\Domain\BugReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BugReportTestFixture;
use Tests\TestCase;

class BugReportCreateTest extends TestCase
{
    use BugReportTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Storage::fake('local');
    }

    public function test_authenticated_user_can_submit_bug_report(): void
    {
        $outlet = $this->createBugReportOutlet();
        $user = $this->actingAsBugReporter($outlet);

        $response = $this->postJson('/api/v1/bug-reports', [
            'outletId' => (int) $outlet->id,
            'title' => 'Checkout button broken',
            'message' => 'Cannot complete payment on POS.',
            'severity' => BugReport::SEVERITY_HIGH,
            'currentRoute' => '/pos',
            'browser' => 'Chrome',
            'userAgent' => 'Mozilla/5.0 Test',
            'viewport' => '1920x1080',
            'appVersion' => '1.0.0',
            'diagnosticsJson' => json_encode([
                'records' => [
                    ['type' => 'api_error', 'message' => '500 on /orders', 'authorization' => 'Bearer secret'],
                ],
            ]),
            'screenshot' => UploadedFile::fake()->image('screen.png', 400, 300),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Checkout button broken')
            ->assertJsonPath('data.severity', BugReport::SEVERITY_HIGH)
            ->assertJsonPath('data.status', BugReport::STATUS_OPEN);

        $this->assertDatabaseHas('bug_reports', [
            'reporter_user_id' => (int) $user->id,
            'title' => 'Checkout button broken',
        ]);

        $report = BugReport::query()->firstOrFail();
        $this->assertIsArray($report->diagnostics_json);
        $this->assertSame('[REDACTED]', $report->diagnostics_json['records'][0]['authorization'] ?? null);
        $this->assertDatabaseHas('bug_report_attachments', ['bug_report_id' => (int) $report->id]);
    }

    public function test_unauthenticated_user_cannot_submit(): void
    {
        $this->postJson('/api/v1/bug-reports', [
            'title' => 'Test',
            'message' => 'Test message',
        ])->assertUnauthorized();
    }
}
