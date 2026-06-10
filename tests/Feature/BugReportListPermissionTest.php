<?php

namespace Tests\Feature;

use App\Models\Modules\System\Domain\BugReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Concerns\BugReportTestFixture;
use Tests\TestCase;

class BugReportListPermissionTest extends TestCase
{
    use BugReportTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_normal_user_cannot_list_bug_reports(): void
    {
        $outlet = $this->createBugReportOutlet();
        $user = $this->actingAsBugReporter($outlet);

        BugReport::query()->create([
            'outlet_id' => $outlet->id,
            'reporter_user_id' => $user->id,
            'title' => 'Hidden',
            'message' => 'Should not list',
            'severity' => BugReport::SEVERITY_LOW,
            'status' => BugReport::STATUS_OPEN,
        ]);

        $this->getJson('/api/v1/bug-reports')->assertForbidden();
    }

    public function test_admin_can_list_and_view_detail(): void
    {
        $outlet = $this->createBugReportOutlet();
        $reporter = $this->actingAsBugReporter($outlet);

        $report = BugReport::query()->create([
            'outlet_id' => $outlet->id,
            'reporter_user_id' => $reporter->id,
            'title' => 'Admin visible',
            'message' => 'Detail test',
            'severity' => BugReport::SEVERITY_MEDIUM,
            'status' => BugReport::STATUS_OPEN,
            'current_route' => '/dashboard',
        ]);

        $admin = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($admin);

        $this->getJson('/api/v1/bug-reports')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Admin visible');

        $this->getJson('/api/v1/bug-reports/'.$report->id)
            ->assertOk()
            ->assertJsonPath('data.currentRoute', '/dashboard');
    }
}
