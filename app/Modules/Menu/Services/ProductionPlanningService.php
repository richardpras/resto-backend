<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ProductionPlanningService
{
    public function __construct(
        private readonly RecipeVersionService $recipeVersionService,
        private readonly MenuProductionAuditService $auditService,
    ) {}

    /**
     * @param array<int,array{menuItemId:int,quantity:float}> $menuDemands
     * @return array<string,mixed>
     */
    public function generateProductionPlan(
        int $outletId,
        array $menuDemands,
        ?User $actor = null,
    ): array {
        $ingredientDemand = $this->generateIngredientDemand($outletId, $menuDemands);
        $aggregated = $this->aggregateRequirements($ingredientDemand, $outletId);

        $this->auditService->log('production_plan_generated', $outletId, $outletId, $actor, [
            'menuDemandCount' => count($menuDemands),
            'ingredientCount' => count($aggregated),
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'menuDemand' => $menuDemands,
            'ingredientDemand' => $ingredientDemand,
            'requirements' => $aggregated,
        ];
    }

    /**
     * @param array<int,array{menuItemId:int,quantity:float}> $menuDemands
     * @return array<int,array<string,mixed>>
     */
    public function generateIngredientDemand(int $outletId, array $menuDemands): array
    {
        $lines = [];

        foreach ($menuDemands as $demand) {
            $menuItemId = (int) $demand['menuItemId'];
            $menuQty = (float) $demand['quantity'];
            $recipeLines = $this->recipeVersionService->getActiveRecipeLines($menuItemId);

            foreach ($recipeLines as $line) {
                $ingredientId = (int) $line['ingredientId'];
                $requiredQty = round($menuQty * (float) $line['quantity'], 4);
                $key = $menuItemId.'-'.$ingredientId;

                if (! isset($lines[$key])) {
                    $lines[$key] = [
                        'menuItemId' => (string) $menuItemId,
                        'ingredientId' => (string) $ingredientId,
                        'unit' => $line['unit'],
                        'menuQuantity' => $menuQty,
                        'recipeQuantityPerUnit' => (float) $line['quantity'],
                        'requiredQuantity' => $requiredQty,
                    ];
                } else {
                    $lines[$key]['requiredQuantity'] = round(
                        (float) $lines[$key]['requiredQuantity'] + $requiredQty,
                        4,
                    );
                }
            }
        }

        $this->auditService->log('ingredient_demand_generated', $outletId, $outletId, null, [
            'lineCount' => count($lines),
        ], entityType: 'outlet');

        return array_values($lines);
    }

    /**
     * @param array<int,array<string,mixed>> $ingredientDemand
     * @return array<int,array<string,mixed>>
     */
    public function aggregateRequirements(array $ingredientDemand, int $outletId): array
    {
        $aggregated = [];

        foreach ($ingredientDemand as $line) {
            $ingredientId = (int) $line['ingredientId'];
            $required = (float) $line['requiredQuantity'];

            if (! isset($aggregated[$ingredientId])) {
                $ingredient = Ingredient::query()->find($ingredientId);
                $available = $this->resolveAvailableStock($ingredientId, $outletId);
                $aggregated[$ingredientId] = [
                    'ingredientId' => (string) $ingredientId,
                    'ingredientName' => $ingredient?->name,
                    'unit' => $line['unit'] ?? $ingredient?->unit,
                    'requiredQuantity' => 0.0,
                    'availableStock' => $available,
                    'shortageQuantity' => 0.0,
                ];
            }

            $aggregated[$ingredientId]['requiredQuantity'] = round(
                (float) $aggregated[$ingredientId]['requiredQuantity'] + $required,
                4,
            );
        }

        foreach ($aggregated as $ingredientId => $row) {
            $shortage = max(0, round((float) $row['requiredQuantity'] - (float) $row['availableStock'], 4));
            $aggregated[$ingredientId]['shortageQuantity'] = $shortage;
        }

        return array_values($aggregated);
    }

    /**
     * @return array<int,array{menuItemId:int,quantity:float}>
     */
    public function deriveMenuDemandFromOrders(int $outletId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.outlet_id', $outletId)
            ->where('orders.payment_status', 'paid')
            ->whereNotNull('order_items.item_id');

        if ($fromDate !== null && $fromDate !== '') {
            $query->whereDate('orders.created_at', '>=', $fromDate);
        }
        if ($toDate !== null && $toDate !== '') {
            $query->whereDate('orders.created_at', '<=', $toDate);
        }

        return $query
            ->selectRaw('order_items.item_id as menu_item_id, SUM(order_items.qty) as total_qty')
            ->groupBy('order_items.item_id')
            ->get()
            ->map(static fn ($row): array => [
                'menuItemId' => (int) $row->menu_item_id,
                'quantity' => (float) $row->total_qty,
            ])
            ->values()
            ->all();
    }

    private function resolveAvailableStock(int $ingredientId, int $outletId): float
    {
        $ledgerStock = DB::table('inventory_stocks')
            ->where('ingredient_id', $ingredientId)
            ->where('outlet_id', $outletId)
            ->value('stock');

        if ($ledgerStock !== null) {
            return (float) $ledgerStock;
        }

        return (float) (Ingredient::query()->whereKey($ingredientId)->value('stock') ?? 0);
    }
}
