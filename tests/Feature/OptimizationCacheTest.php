<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuIntelligenceCacheService;
use App\Modules\Menu\Services\MenuOptimizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class OptimizationCacheTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_optimization_summary_is_cached_per_outlet(): void
    {
        Cache::flush();
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, menuPrice: 35000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 15000);

        $service = app(MenuOptimizationService::class);
        $service->generateRecommendations((int) $outlet->id);

        $cache = app(MenuIntelligenceCacheService::class);
        $this->assertTrue($cache->has(
            (int) $outlet->id,
            MenuIntelligenceCacheService::PREFIX_OPTIMIZATION,
            md5('|'),
        ));

        $start = microtime(true);
        $service->generateRecommendations((int) $outlet->id);
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(1000, $elapsedMs);
    }
}
