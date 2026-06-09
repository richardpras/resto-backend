<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuItemOutlet;
use App\Models\Modules\Menu\Domain\MenuRecipeCostSetting;
use App\Models\Modules\Orders\Domain\OrderItemCostSnapshot;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryCostService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class RecipeCostService
{
    public function __construct(
        private readonly InventoryCostService $inventoryCostService,
        private readonly MenuCostingAuditService $auditService,
    ) {}

    public function calculateYieldAdjustedCost(float $rawCost, float $yieldPercent): float
    {
        $yield = max(0.01, min(100.0, $yieldPercent));

        return round($rawCost / ($yield / 100), 4);
    }

    public function calculateWasteAdjustedCost(float $yieldAdjustedCost, float $wastePercent): float
    {
        $waste = max(0.0, $wastePercent);

        return round($yieldAdjustedCost * (1 + ($waste / 100)), 4);
    }

    public function calculateMenuCost(int $menuItemId, int $outletId, ?User $actor = null): float
    {
        $breakdown = $this->calculateMenuCostBreakdown($menuItemId, $outletId, $actor, logCalculated: true);

        return (float) $breakdown['finalTheoreticalCost'];
    }

    /** @return array<string,mixed> */
    public function calculateMenuCostBreakdown(
        int $menuItemId,
        int $outletId,
        ?User $actor = null,
        bool $logCalculated = false,
    ): array {
        $menuItem = MenuItem::query()->with('recipes.ingredient')->find($menuItemId);
        abort_if($menuItem === null, Response::HTTP_NOT_FOUND, 'Menu item not found.');

        $settings = $this->resolveCostSettings($menuItemId);
        $ingredientLines = [];
        $rawCost = 0.0;

        foreach ($menuItem->recipes as $recipe) {
            $ingredientId = (int) $recipe->inventory_item_id;
            $quantity = (float) $recipe->quantity;
            $averageCost = $this->inventoryCostService->resolveUnitCost($ingredientId, $outletId);
            $lineCost = round($averageCost * $quantity, 4);
            $rawCost += $lineCost;

            $ingredientLines[] = [
                'inventoryItemId' => (string) $ingredientId,
                'ingredientName' => $recipe->ingredient?->name,
                'unit' => $recipe->ingredient?->unit,
                'quantity' => $quantity,
                'averageCost' => $averageCost,
                'lineCost' => $lineCost,
            ];
        }

        $rawCost = round($rawCost, 4);
        $yieldAdjustedCost = $this->calculateYieldAdjustedCost($rawCost, (float) $settings->yield_percent);
        $wasteAdjustedCost = $this->calculateWasteAdjustedCost($yieldAdjustedCost, (float) $settings->waste_percent);
        $sellingPrice = $this->resolveSellingPrice($menuItem, $outletId);

        $result = [
            'menuItemId' => (string) $menuItem->id,
            'menuItemName' => $menuItem->name,
            'outletId' => $outletId,
            'yieldPercent' => (float) $settings->yield_percent,
            'wastePercent' => (float) $settings->waste_percent,
            'ingredients' => $ingredientLines,
            'rawCost' => $rawCost,
            'yieldAdjustedCost' => $yieldAdjustedCost,
            'wasteAdjustedCost' => $wasteAdjustedCost,
            'finalTheoreticalCost' => $wasteAdjustedCost,
            'sellingPrice' => $sellingPrice,
        ];

        if ($logCalculated) {
            $this->auditService->log('menu_cost_calculated', $menuItemId, $outletId, $actor, [
                'rawCost' => $rawCost,
                'finalTheoreticalCost' => $wasteAdjustedCost,
            ]);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function calculateTheoreticalFoodCost(int $menuItemId, int $outletId, ?User $actor = null): array
    {
        $breakdown = $this->calculateMenuCostBreakdown($menuItemId, $outletId, $actor, logCalculated: false);
        $sellingPrice = (float) $breakdown['sellingPrice'];
        $finalCost = (float) $breakdown['finalTheoreticalCost'];
        $percent = $sellingPrice > 0
            ? round(($finalCost / $sellingPrice) * 100, 4)
            : 0.0;

        $this->auditService->log('food_cost_calculated', $menuItemId, $outletId, $actor, [
            'sellingPrice' => $sellingPrice,
            'finalCost' => $finalCost,
            'theoreticalFoodCostPercent' => $percent,
        ]);

        return [
            'menuItemId' => (string) $menuItemId,
            'outletId' => $outletId,
            'sellingPrice' => $sellingPrice,
            'rawCost' => (float) $breakdown['rawCost'],
            'yieldCost' => (float) $breakdown['yieldAdjustedCost'],
            'wasteCost' => (float) $breakdown['wasteAdjustedCost'],
            'finalTheoreticalCost' => $finalCost,
            'theoreticalFoodCostPercent' => $percent,
            'yieldPercent' => (float) $breakdown['yieldPercent'],
            'wastePercent' => (float) $breakdown['wastePercent'],
        ];
    }

    /** @return array<string,mixed> */
    public function calculateHistoricalCost(
        int $menuItemId,
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $current = $this->calculateMenuCostBreakdown($menuItemId, $outletId, logCalculated: false);
        $currentCost = (float) $current['finalTheoreticalCost'];

        $query = OrderItemCostSnapshot::query()
            ->where('menu_item_id', $menuItemId)
            ->where('outlet_id', $outletId);

        if ($fromDate !== null && $fromDate !== '') {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate !== null && $toDate !== '') {
            $query->whereDate('created_at', '<=', $toDate);
        }

        /** @var Collection<int, OrderItemCostSnapshot> $snapshots */
        $snapshots = $query->orderByDesc('created_at')->get();

        $rows = $snapshots->map(function (OrderItemCostSnapshot $snapshot) use ($currentCost): array {
            $snapshotCost = (float) $snapshot->cost_per_unit;
            $difference = round($currentCost - $snapshotCost, 4);
            $variancePercent = $snapshotCost > 0
                ? round(($difference / $snapshotCost) * 100, 4)
                : 0.0;

            return [
                'orderItemId' => (string) $snapshot->order_item_id,
                'snapshotCost' => $snapshotCost,
                'snapshotTotalCost' => (float) $snapshot->total_cost,
                'currentCost' => $currentCost,
                'difference' => $difference,
                'variancePercent' => $variancePercent,
                'averageCostVersion' => $snapshot->average_cost_version,
                'snapshotAt' => $snapshot->created_at?->toIso8601String(),
            ];
        })->values()->all();

        $this->auditService->log('historical_cost_compared', $menuItemId, $outletId, $actor, [
            'currentCost' => $currentCost,
            'snapshotCount' => count($rows),
        ]);

        return [
            'menuItemId' => (string) $menuItemId,
            'outletId' => $outletId,
            'currentCost' => $currentCost,
            'history' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    public function recalculateMenuCost(int $menuItemId, int $outletId, ?User $actor = null): array
    {
        $breakdown = $this->calculateMenuCostBreakdown($menuItemId, $outletId, logCalculated: false);

        $this->auditService->log('menu_cost_recalculated', $menuItemId, $outletId, $actor, [
            'rawCost' => $breakdown['rawCost'],
            'finalTheoreticalCost' => $breakdown['finalTheoreticalCost'],
        ]);

        return $breakdown;
    }

    public function updateYieldPercent(int $menuItemId, float $yieldPercent, ?User $actor = null, ?int $outletId = null): MenuRecipeCostSetting
    {
        $settings = $this->resolveCostSettings($menuItemId);
        $settings->update(['yield_percent' => max(0.01, min(100.0, $yieldPercent))]);

        $this->auditService->log('yield_updated', $menuItemId, $outletId, $actor, [
            'yieldPercent' => (float) $settings->yield_percent,
        ]);

        return $settings->fresh();
    }

    public function updateWastePercent(int $menuItemId, float $wastePercent, ?User $actor = null, ?int $outletId = null): MenuRecipeCostSetting
    {
        $settings = $this->resolveCostSettings($menuItemId);
        $settings->update(['waste_percent' => max(0.0, $wastePercent)]);

        $this->auditService->log('waste_updated', $menuItemId, $outletId, $actor, [
            'wastePercent' => (float) $settings->waste_percent,
        ]);

        return $settings->fresh();
    }

    private function resolveCostSettings(int $menuItemId): MenuRecipeCostSetting
    {
        return MenuRecipeCostSetting::query()->firstOrCreate(
            ['menu_item_id' => $menuItemId],
            [
                'yield_percent' => 100,
                'waste_percent' => 0,
                'is_active' => true,
            ],
        );
    }

    private function resolveSellingPrice(MenuItem $menuItem, int $outletId): float
    {
        $mapping = MenuItemOutlet::query()
            ->where('menu_item_id', $menuItem->id)
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->first();

        if ($mapping !== null && $mapping->price_override !== null) {
            return (float) $mapping->price_override;
        }

        return (float) $menuItem->price;
    }
}
