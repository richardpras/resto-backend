<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\DashboardService;
use App\Modules\Menu\Services\MenuIntelligenceCacheService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class DashboardCacheTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_dashboard_summary_is_cached_per_outlet(): void
    {
        Cache::flush();
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, menuPrice: 50000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $service = app(DashboardService::class);
        $service->getSummary((int) $outlet->id);

        $cache = app(MenuIntelligenceCacheService::class);
        $this->assertTrue($cache->has((int) $outlet->id, MenuIntelligenceCacheService::PREFIX_DASHBOARD));

        $start = microtime(true);
        $cached = $service->getSummary((int) $outlet->id);
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertArrayHasKey('kpis', $cached);
        $this->assertLessThan(500, $elapsedMs);
    }
}
