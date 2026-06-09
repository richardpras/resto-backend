<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\MenuAutomationService;
use App\Modules\Menu\Services\MenuIntelligenceCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class AutomationCacheTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_automation_summary_is_cached_per_outlet(): void
    {
        Cache::flush();
        $outlet = $this->createValuationOutlet();

        $service = app(MenuAutomationService::class);
        $service->getDashboardSummary((int) $outlet->id);

        $cache = app(MenuIntelligenceCacheService::class);
        $this->assertTrue($cache->has((int) $outlet->id, MenuIntelligenceCacheService::PREFIX_AUTOMATION));

        $start = microtime(true);
        $service->getDashboardSummary((int) $outlet->id);
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(500, $elapsedMs);
    }
}
