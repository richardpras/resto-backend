<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuOptimizationSnapshot;
use App\Modules\Menu\Services\MenuOptimizationSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class HistoricalIntegrityOptimizationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_historical_optimization_snapshots_are_never_overwritten(): void
    {
        $outlet = $this->createValuationOutlet();
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);

        $date = now()->toDateString();
        MenuOptimizationSnapshot::query()->create([
            'snapshot_date' => $date,
            'outlet_id' => $outlet->id,
            'menu_item_id' => $menu['menuId'],
            'recommendation_type' => MenuOptimizationSnapshot::TYPE_ENGINEERING,
            'recommendation_json' => ['classification' => 'STAR', 'primaryRecommendation' => 'maintain'],
            'projected_margin_percent' => 99.5,
            'projected_profit_increase' => 5000000,
        ]);

        app(MenuOptimizationSnapshotService::class)->createSnapshot((int) $outlet->id, $date);

        $snapshot = MenuOptimizationSnapshot::query()
            ->where('menu_item_id', $menu['menuId'])
            ->where('recommendation_type', MenuOptimizationSnapshot::TYPE_ENGINEERING)
            ->firstOrFail();

        $this->assertSame(99.5, (float) $snapshot->projected_margin_percent);
        $this->assertSame(5000000.0, (float) $snapshot->projected_profit_increase);
        $this->assertSame('STAR', $snapshot->recommendation_json['classification']);
    }
}
