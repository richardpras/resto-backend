<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\InventoryValuation;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItemCostSnapshot;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryCostingPolicyService;
use App\Modules\Menu\Services\OrderItemRecipeSnapshotService;
use App\Modules\Menu\Services\RecipeCostService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Support\Facades\DB;

final class OrderItemCostSnapshotService
{
    public function __construct(
        private readonly RecipeCostService $recipeCostService,
        private readonly RecipeVersionService $recipeVersionService,
        private readonly OrderItemRecipeSnapshotService $recipeSnapshotService,
        private readonly InventoryValuationAuditService $auditService,
        private readonly InventoryCostingPolicyService $costingPolicyService,
    ) {}

    public function snapshotForPaidOrder(Order $order, ?User $actor = null): void
    {
        $outletId = (int) $order->outlet_id;
        if ($outletId < 1) {
            return;
        }

        $order->loadMissing('items');
        if ($order->items->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($order, $outletId, $actor): void {
            foreach ($order->items as $item) {
                if ($item->item_id === null) {
                    continue;
                }

                $existing = OrderItemCostSnapshot::query()
                    ->where('order_item_id', $item->id)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    continue;
                }

                $menuItemId = (int) $item->item_id;
                $recipeSnapshot = $this->recipeSnapshotService->snapshotForOrderItem($item, $outletId, $actor);
                $activeVersion = $this->recipeVersionService->getActiveVersion($menuItemId);
                $costPerUnit = $this->recipeCostService->calculateMenuCost($menuItemId, $outletId, $actor);
                $totalCost = round($costPerUnit * (float) $item->qty, 4);
                $version = $this->resolveAverageCostVersion($menuItemId, $outletId, (int) $activeVersion->version_number);

                $snapshot = OrderItemCostSnapshot::query()->create([
                    'order_item_id' => (int) $item->id,
                    'menu_item_id' => $menuItemId,
                    'recipe_version_id' => $activeVersion->id,
                    'outlet_id' => $outletId,
                    'cost_per_unit' => $costPerUnit,
                    'total_cost' => $totalCost,
                    'average_cost_version' => $version,
                    'costing_method_snapshot' => $this->costingPolicyService->getMethod(),
                    'created_at' => now(),
                ]);

                $this->auditService->log(
                    'inventory_cost_snapshot_created',
                    (int) $snapshot->id,
                    $outletId,
                    $actor,
                    [
                        'orderItemId' => (int) $item->id,
                        'menuItemId' => $menuItemId,
                        'recipeVersionId' => (int) $activeVersion->id,
                        'recipeVersionNumber' => (int) $activeVersion->version_number,
                        'recipeSnapshotId' => $recipeSnapshot?->id,
                        'costPerUnit' => $costPerUnit,
                        'totalCost' => $totalCost,
                        'averageCostVersion' => $version,
                    ],
                );
            }
        });
    }

    private function resolveAverageCostVersion(int $menuItemId, int $outletId, int $recipeVersionNumber): string
    {
        $ingredientIds = $this->recipeVersionService->getActiveRecipeLines($menuItemId);
        $ids = collect($ingredientIds)->pluck('ingredientId')->map(static fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return 'recipe-v'.$recipeVersionNumber.'-'.now()->toIso8601String();
        }

        $latest = InventoryValuation::query()
            ->where('outlet_id', $outletId)
            ->whereIn('ingredient_id', $ids)
            ->max('last_updated_at');

        $valuationStamp = $latest !== null ? (string) $latest : now()->toIso8601String();

        return 'recipe-v'.$recipeVersionNumber.'-'.$valuationStamp;
    }
}
