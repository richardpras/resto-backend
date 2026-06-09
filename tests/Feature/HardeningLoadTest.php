<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\DashboardService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class HardeningLoadTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_dashboard_summary_remains_responsive_across_multiple_outlets(): void
    {
        Cache::flush();
        $outletIds = [];

        for ($i = 0; $i < 5; $i++) {
            $outlet = $this->createValuationOutlet();
            $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
            $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, menuPrice: 20000 + ($i * 1000));
            app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 5000);
            app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);
            $outletIds[] = (int) $outlet->id;
        }

        $service = app(DashboardService::class);
        foreach ($outletIds as $outletId) {
            $start = microtime(true);
            $summary = $service->getSummary($outletId);
            $elapsedMs = (microtime(true) - $start) * 1000;
            $this->assertArrayHasKey('health', $summary);
            $this->assertLessThan(5000, $elapsedMs);
        }

        $cachedStart = microtime(true);
        $service->getSummary($outletIds[0]);
        $cachedMs = (microtime(true) - $cachedStart) * 1000;
        $this->assertLessThan(500, $cachedMs);
    }
}
