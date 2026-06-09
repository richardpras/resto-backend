<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryValuation;
use App\Models\User;
use App\Modules\Menu\Services\MenuAnalyticsAuditService;
use Illuminate\Support\Facades\DB;

final class InventoryAnalyticsService
{
    public function __construct(
        private readonly MenuAnalyticsAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function getSummary(int $outletId, ?User $actor = null): array
    {
        $data = [
            'outletId' => $outletId,
            'inventoryValue' => $this->getTotalInventoryValue($outletId),
            'turnover' => $this->getInventoryTurnover($outletId),
            'fastMoving' => $this->getFastMovingIngredients($outletId, 5),
            'slowMoving' => $this->getSlowMovingIngredients($outletId, 5),
            'deadStock' => $this->getDeadStockIngredients($outletId),
        ];

        $this->auditService->log('inventory_analytics_generated', $outletId, $outletId, $actor, [
            'type' => 'summary',
        ]);

        return $data;
    }

    /** @return array<int,array<string,mixed>> */
    public function getFastMovingIngredients(int $outletId, int $limit = 10, int $days = 30): array
    {
        return $this->rankIngredientMovement($outletId, $limit, descending: true, days: $days);
    }

    /** @return array<int,array<string,mixed>> */
    public function getSlowMovingIngredients(int $outletId, int $limit = 10, int $days = 30): array
    {
        return $this->rankIngredientMovement($outletId, $limit, descending: false, days: $days);
    }

    /** @return array<int,array<string,mixed>> */
    public function getDeadStockIngredients(int $outletId, int $days = 90, ?User $actor = null): array
    {
        $since = now()->subDays($days)->toDateString();
        $movedIds = DB::table('stock_movements')
            ->where('outlet_id', $outletId)
            ->whereDate('created_at', '>=', $since)
            ->distinct()
            ->pluck('inventory_item_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $valuations = InventoryValuation::query()
            ->with('ingredient')
            ->where('outlet_id', $outletId)
            ->where('stock_quantity', '>', 0)
            ->when($movedIds !== [], fn ($q) => $q->whereNotIn('ingredient_id', $movedIds))
            ->get();

        $rows = $valuations->map(static fn (InventoryValuation $v): array => [
            'ingredientId' => (string) $v->ingredient_id,
            'ingredientName' => $v->ingredient?->name,
            'stockQuantity' => (float) $v->stock_quantity,
            'inventoryValue' => (float) $v->inventory_value,
            'daysWithoutMovement' => $days,
        ])->values()->all();

        if ($rows !== []) {
            $this->auditService->log('dead_stock_detected', $outletId, $outletId, $actor, [
                'count' => count($rows),
                'periodDays' => $days,
            ]);
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    public function getInventoryTurnover(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $cogsQuery = DB::table('stock_movements')
            ->where('outlet_id', $outletId)
            ->where('type', 'sale');

        if ($fromDate) {
            $cogsQuery->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $cogsQuery->whereDate('created_at', '<=', $toDate);
        }

        $cogs = (float) $cogsQuery->sum('total_cost');
        $avgInventory = $this->getTotalInventoryValue($outletId);
        $turnover = $avgInventory > 0 ? round($cogs / $avgInventory, 4) : 0.0;

        $this->auditService->log('inventory_analytics_generated', $outletId, $outletId, $actor, [
            'type' => 'turnover',
            'turnover' => $turnover,
        ]);

        return [
            'outletId' => $outletId,
            'cogs' => $cogs,
            'averageInventoryValue' => $avgInventory,
            'inventoryTurnover' => $turnover,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function getInventoryValueTrend(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $current = $this->getTotalInventoryValue($outletId);

        $rows = [[
            'date' => now()->toDateString(),
            'inventoryValue' => $current,
        ]];

        $this->auditService->log('inventory_analytics_generated', $outletId, $outletId, $actor, [
            'type' => 'value_trend',
        ]);

        return $rows;
    }

    private function getTotalInventoryValue(int $outletId): float
    {
        return (float) InventoryValuation::query()
            ->where('outlet_id', $outletId)
            ->sum('inventory_value');
    }

    /** @return array<int,array<string,mixed>> */
    private function rankIngredientMovement(int $outletId, int $limit, bool $descending, int $days): array
    {
        $since = now()->subDays($days)->toDateString();

        $rows = DB::table('stock_movements')
            ->where('outlet_id', $outletId)
            ->whereDate('created_at', '>=', $since)
            ->selectRaw('inventory_item_id')
            ->selectRaw('SUM(ABS(quantity)) as total_qty')
            ->groupBy('inventory_item_id')
            ->get()
            ->map(static function ($row): array {
                $ingredient = Ingredient::query()->find((int) $row->inventory_item_id);

                return [
                    'ingredientId' => (string) $row->inventory_item_id,
                    'ingredientName' => $ingredient?->name,
                    'totalQuantityMoved' => (float) $row->total_qty,
                ];
            })
            ->all();

        usort($rows, static fn (array $a, array $b): int => $descending
            ? $b['totalQuantityMoved'] <=> $a['totalQuantityMoved']
            : $a['totalQuantityMoved'] <=> $b['totalQuantityMoved']);

        return array_slice($rows, 0, max(1, $limit));
    }
}
