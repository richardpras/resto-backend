<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\MenuForecastingService;
use App\Modules\Menu\Services\MenuIntelligenceCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ForecastCacheTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_forecast_summary_is_cached_per_outlet(): void
    {
        Cache::flush();
        $outlet = $this->createValuationOutlet();
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);

        $service = app(MenuForecastingService::class);
        $targetDate = now()->addDay()->toDateString();
        $service->getSummary((int) $outlet->id);

        $cache = app(MenuIntelligenceCacheService::class);
        $this->assertTrue($cache->has(
            (int) $outlet->id,
            MenuIntelligenceCacheService::PREFIX_FORECAST,
            md5($targetDate),
        ));

        $start = microtime(true);
        $service->getSummary((int) $outlet->id);
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(1000, $elapsedMs);
    }
}
