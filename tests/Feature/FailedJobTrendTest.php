<?php

namespace Tests\Feature;

use App\Models\Modules\System\Domain\FailedJobSnapshot;
use App\Modules\System\Services\FailedJobSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FailedJobTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class FailedJobTrendTest extends TestCase
{
    use FailedJobTestFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_snapshot_captures_daily_totals(): void
    {
        $this->seedFailedJob('Payments\\RecoverStalePaymentsJob');
        $this->seedFailedJob('Menu\\Jobs\\CreateDailyAnalyticsSnapshotJob');

        $snapshot = app(FailedJobSnapshotService::class)->capture();

        $this->assertSame(2, (int) $snapshot->total_failures);
        $this->assertSame(1, (int) $snapshot->critical_failures);
        $this->assertDatabaseHas('failed_job_snapshots', [
            'snapshot_date' => now()->toDateString(),
            'total_failures' => 2,
        ]);
    }

    public function test_trends_api_returns_history(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();

        FailedJobSnapshot::query()->create([
            'snapshot_date' => now()->subDays(2)->toDateString(),
            'total_failures' => 5,
            'critical_failures' => 2,
            'resolved_failures' => 1,
            'health_status' => 'warning',
        ]);

        FailedJobSnapshot::query()->create([
            'snapshot_date' => now()->subDay()->toDateString(),
            'total_failures' => 3,
            'critical_failures' => 1,
            'resolved_failures' => 2,
            'health_status' => 'warning',
        ]);

        $response = $this->getJson('/api/v1/system/failed-jobs/trends');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }
}
