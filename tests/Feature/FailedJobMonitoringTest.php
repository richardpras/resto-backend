<?php

namespace Tests\Feature;

use App\Modules\System\Services\FailedJobMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FailedJobTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class FailedJobMonitoringTest extends TestCase
{
    use FailedJobTestFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_aggregate_returns_failure_intelligence(): void
    {
        $this->seedFailedJob('Payments\\RecoverStalePaymentsJob', 'payments-recovery');
        $this->seedFailedJob('Menu\\Jobs\\CreateDailyAnalyticsSnapshotJob', 'default');
        $this->seedFailedJob('Payments\\RecoverStalePaymentsJob', 'payments-recovery');

        $summary = app(FailedJobMonitoringService::class)->aggregate();

        $this->assertSame(3, $summary['failedJobs']);
        $this->assertSame(2, $summary['criticalFailures']);
        $this->assertSame(1, $summary['repeatFailures']);
        $this->assertArrayHasKey('healthStatus', $summary);
        $this->assertArrayHasKey('healthScore', $summary);
    }

    public function test_list_failures_api_requires_settings_permission(): void
    {
        $this->getJson('/api/v1/system/failed-jobs')->assertUnauthorized();
    }

    public function test_list_failures_api_returns_rows(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createFailedJobOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->seedFailedJob('Payments\\RecoverStalePaymentsJob', 'payments-recovery', (int) $outlet->id);

        $response = $this->getJson('/api/v1/system/failed-jobs');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.jobClass', 'RecoverStalePaymentsJob');
    }
}
