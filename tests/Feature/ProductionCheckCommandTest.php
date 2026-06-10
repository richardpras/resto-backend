<?php

namespace Tests\Feature;

use App\Modules\System\Services\ProductionCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductionCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_production_check_returns_json_structure(): void
    {
        config(['app.env' => 'local', 'app.debug' => false]);
        ProductionCheckService::recordSchedulerHeartbeat();

        Artisan::call('system:production-check', ['--json' => true, '--allow-non-production' => true]);
        $output = Artisan::output();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertContains($decoded['status'], ['healthy', 'degraded', 'critical']);
    }

    public function test_fails_when_app_debug_enabled_in_strict_mode(): void
    {
        config(['app.env' => 'production', 'app.debug' => true]);
        ProductionCheckService::recordSchedulerHeartbeat();

        $exitCode = Artisan::call('system:production-check', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertNotSame(0, $exitCode);
        $debugCheck = collect($decoded['checks'] ?? [])->firstWhere('id', 'application.debug');
        $this->assertSame('fail', $debugCheck['status'] ?? null);
    }

    public function test_scheduler_heartbeat_check_passes_when_recent(): void
    {
        config(['app.env' => 'local', 'app.debug' => false]);
        Cache::put('system:scheduler:last_run_at', now()->toIso8601String(), 3600);

        Artisan::call('system:production-check', ['--json' => true, '--allow-non-production' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $schedulerCheck = collect($decoded['checks'] ?? [])->firstWhere('id', 'scheduler.last_run');
        $this->assertSame('pass', $schedulerCheck['status'] ?? null);
    }

    public function test_service_run_returns_checks(): void
    {
        config(['app.debug' => false]);
        ProductionCheckService::recordSchedulerHeartbeat();

        $result = app(ProductionCheckService::class)->run(false);

        $this->assertNotEmpty($result['checks']);
        $this->assertArrayHasKey('pass', $result['summary']);
        $ids = collect($result['checks'])->pluck('id')->all();
        $this->assertContains('database.connection', $ids);
        $this->assertContains('application.key', $ids);
    }
}
