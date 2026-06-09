<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuAnalyticsSnapshot;
use App\Modules\Menu\Services\MenuHealthService;
use App\Modules\Menu\Services\MenuIntelligenceCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class MenuHealthServiceTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_system_health_reports_missing_snapshots(): void
    {
        $outlet = $this->createValuationOutlet();

        $health = app(MenuHealthService::class)->getSystemHealth((int) $outlet->id);

        $this->assertArrayHasKey('score', $health);
        $this->assertSame('missing', $health['analytics']['status']);
        $this->assertNotEmpty($health['issues']);
        $this->assertLessThan(100, $health['score']);
    }

    public function test_system_health_improves_with_recent_snapshot_and_cache(): void
    {
        Cache::flush();
        $outlet = $this->createValuationOutlet();
        $oid = (int) $outlet->id;

        MenuAnalyticsSnapshot::query()->create([
            'snapshot_date' => now()->toDateString(),
            'outlet_id' => $oid,
            'average_food_cost_percent' => 30,
            'average_margin_percent' => 50,
            'inventory_value' => 1000,
            'daily_cogs' => 200,
            'production_efficiency_percent' => 80,
            'total_sales' => 5000,
            'total_orders' => 10,
        ]);

        $cache = app(MenuIntelligenceCacheService::class);
        $cache->remember($oid, MenuIntelligenceCacheService::PREFIX_DASHBOARD, 60, fn () => ['ok' => true]);

        $health = app(MenuHealthService::class)->getSystemHealth($oid);

        $this->assertSame('ok', $health['analytics']['status']);
        $this->assertTrue($health['cache']['dashboard']);
    }
}
