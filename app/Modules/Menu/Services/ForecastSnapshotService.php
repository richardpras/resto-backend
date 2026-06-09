<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\ForecastSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ForecastSnapshotService
{
    public function __construct(
        private readonly DemandForecastService $demandForecast,
        private readonly RevenueForecastService $revenueForecast,
        private readonly IngredientForecastService $ingredientForecast,
        private readonly FoodCostForecastService $foodCostForecast,
        private readonly ProductionForecastService $productionForecast,
        private readonly StockRiskForecastService $stockRiskForecast,
        private readonly ForecastAuditService $auditService,
    ) {}

    /** @return Collection<int, ForecastSnapshot> */
    public function createSnapshot(
        int $outletId,
        ?string $snapshotDate = null,
        ?string $forecastDate = null,
        ?User $actor = null,
    ): Collection {
        $snapDate = $snapshotDate ?? now()->toDateString();
        $targetDate = $forecastDate ?? now()->addDay()->toDateString();
        $snapshots = collect();

        DB::transaction(function () use ($outletId, $snapDate, $targetDate, $actor, $snapshots): void {
            $demand = $this->demandForecast->forecastOutlet($outletId, $targetDate, $actor);
            foreach ($demand['items'] as $item) {
                $snapshots->push($this->persist(
                    $outletId,
                    $snapDate,
                    $targetDate,
                    ForecastSnapshot::TYPE_DAILY_DEMAND,
                    (int) $item['menuItemId'],
                    null,
                    (float) $item['predictedQuantity'],
                    0,
                    0,
                    (float) $item['confidenceScore'],
                    $item,
                ));
                $snapshots->push($this->persist(
                    $outletId,
                    $snapDate,
                    $targetDate,
                    ForecastSnapshot::TYPE_WEEKLY_DEMAND,
                    (int) $item['menuItemId'],
                    null,
                    (float) $item['horizons']['weekly'],
                    0,
                    0,
                    (float) $item['confidenceScore'],
                    $item,
                ));
            }

            $revenue = $this->revenueForecast->forecastOutlet($outletId, $targetDate, $actor);
            foreach ($revenue['items'] as $item) {
                $snapshots->push($this->persist(
                    $outletId,
                    $snapDate,
                    $targetDate,
                    ForecastSnapshot::TYPE_DAILY_REVENUE,
                    (int) $item['menuItemId'],
                    null,
                    (float) $item['predictedQuantity'],
                    (float) $item['predictedRevenue'],
                    (float) $item['predictedMargin'],
                    (float) $item['confidenceScore'],
                    $item,
                ));
            }

            $ingredients = $this->ingredientForecast->forecastOutlet($outletId, $targetDate, $actor);
            foreach ($ingredients['ingredients'] as $row) {
                $snapshots->push($this->persist(
                    $outletId,
                    $snapDate,
                    $targetDate,
                    ForecastSnapshot::TYPE_INGREDIENT_CONSUMPTION,
                    null,
                    (int) $row['inventoryItemId'],
                    (float) $row['predictedQuantity'],
                    0,
                    0,
                    (float) $row['confidenceScore'],
                    $row,
                ));
            }

            $foodCost = $this->foodCostForecast->forecastOutlet($outletId, $targetDate, $actor);
            foreach ($foodCost['items'] as $item) {
                $snapshots->push($this->persist(
                    $outletId,
                    $snapDate,
                    $targetDate,
                    ForecastSnapshot::TYPE_FOOD_COST,
                    (int) $item['menuItemId'],
                    null,
                    (float) $item['predictedQuantity'],
                    (float) $item['predictedRevenue'],
                    (float) $item['predictedFoodCost'],
                    (float) $item['confidenceScore'],
                    $item,
                ));
            }

            $production = $this->productionForecast->forecastOutlet($outletId, $targetDate, $actor);
            foreach ($production['recommendations'] as $rec) {
                if (isset($rec['menuItemId'])) {
                    $snapshots->push($this->persist(
                        $outletId,
                        $snapDate,
                        $targetDate,
                        ForecastSnapshot::TYPE_PRODUCTION_REQUIREMENT,
                        (int) $rec['menuItemId'],
                        null,
                        (float) ($rec['prepQuantity'] ?? 0),
                        0,
                        0,
                        0.5,
                        $rec,
                    ));
                }
            }

            $stockRisks = $this->stockRiskForecast->forecastOutlet($outletId, $targetDate, $actor);
            foreach ($stockRisks['risks'] as $risk) {
                $snapshots->push($this->persist(
                    $outletId,
                    $snapDate,
                    $targetDate,
                    ForecastSnapshot::TYPE_STOCK_RISK,
                    null,
                    (int) $risk['inventoryItemId'],
                    (float) $risk['daysRemaining'],
                    0,
                    0,
                    (float) $risk['shortageProbability'],
                    $risk,
                ));
            }

            $this->auditService->log('forecast_snapshot_created', $outletId, $outletId, $actor, [
                'snapshotDate' => $snapDate,
                'forecastDate' => $targetDate,
                'rowCount' => $snapshots->count(),
            ], entityType: 'outlet');
        });

        return $snapshots;
    }

    /** @return Collection<int, ForecastSnapshot> */
    public function getSnapshots(int $outletId, ?string $snapshotDate = null, ?string $forecastDate = null): Collection
    {
        $query = ForecastSnapshot::query()
            ->where('outlet_id', $outletId)
            ->orderByDesc('snapshot_date')
            ->orderBy('forecast_type');

        if ($snapshotDate !== null) {
            $query->whereDate('snapshot_date', $snapshotDate);
        }
        if ($forecastDate !== null) {
            $query->whereDate('forecast_date', $forecastDate);
        }

        return $query->get();
    }

    /** @param array<string,mixed> $metadata */
    private function persist(
        int $outletId,
        string $snapshotDate,
        string $forecastDate,
        string $forecastType,
        ?int $menuItemId,
        ?int $inventoryItemId,
        float $predictedQuantity,
        float $predictedRevenue,
        float $predictedMargin,
        float $confidenceScore,
        array $metadata,
    ): ForecastSnapshot {
        $query = ForecastSnapshot::query()
            ->where('snapshot_date', $snapshotDate)
            ->where('forecast_date', $forecastDate)
            ->where('outlet_id', $outletId)
            ->where('forecast_type', $forecastType)
            ->lockForUpdate();

        if ($menuItemId === null) {
            $query->whereNull('menu_item_id');
        } else {
            $query->where('menu_item_id', $menuItemId);
        }

        if ($inventoryItemId === null) {
            $query->whereNull('inventory_item_id');
        } else {
            $query->where('inventory_item_id', $inventoryItemId);
        }

        $existing = $query->first();
        if ($existing !== null) {
            return $existing;
        }

        return ForecastSnapshot::query()->create([
            'snapshot_date' => $snapshotDate,
            'forecast_date' => $forecastDate,
            'outlet_id' => $outletId,
            'menu_item_id' => $menuItemId,
            'inventory_item_id' => $inventoryItemId,
            'forecast_type' => $forecastType,
            'predicted_quantity' => $predictedQuantity,
            'predicted_revenue' => $predictedRevenue,
            'predicted_margin' => $predictedMargin,
            'confidence_score' => $confidenceScore,
            'metadata_json' => $metadata,
        ]);
    }
}
