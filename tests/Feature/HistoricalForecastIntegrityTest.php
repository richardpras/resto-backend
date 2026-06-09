<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\ForecastSnapshot;
use App\Modules\Menu\Services\ForecastSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class HistoricalForecastIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_historical_forecast_snapshots_are_never_overwritten(): void
    {
        $outlet = $this->createValuationOutlet();
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);
        $snapDate = now()->toDateString();
        $forecastDate = now()->addDay()->toDateString();

        ForecastSnapshot::query()->create([
            'snapshot_date' => $snapDate,
            'forecast_date' => $forecastDate,
            'outlet_id' => $outlet->id,
            'menu_item_id' => $menu['menuId'],
            'inventory_item_id' => null,
            'forecast_type' => ForecastSnapshot::TYPE_DAILY_DEMAND,
            'predicted_quantity' => 999,
            'predicted_revenue' => 0,
            'predicted_margin' => 0,
            'confidence_score' => 0.99,
            'metadata_json' => ['locked' => true],
        ]);

        app(ForecastSnapshotService::class)->createSnapshot((int) $outlet->id, $snapDate, $forecastDate);

        $snapshot = ForecastSnapshot::query()
            ->where('menu_item_id', $menu['menuId'])
            ->where('forecast_type', ForecastSnapshot::TYPE_DAILY_DEMAND)
            ->firstOrFail();

        $this->assertSame(999.0, (float) $snapshot->predicted_quantity);
        $this->assertTrue($snapshot->metadata_json['locked'] ?? false);
    }
}
