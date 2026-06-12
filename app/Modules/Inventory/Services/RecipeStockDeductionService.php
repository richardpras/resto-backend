<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Support\StockEnforcementMode;
use App\Modules\Orders\Services\PosAuditLogService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RecipeStockDeductionService
{
    public function __construct(
        private readonly IngredientOutletStockLedger $ingredientOutletStockLedger,
        private readonly InventoryValuationService $inventoryValuationService,
        private readonly InventoryCostService $inventoryCostService,
        private readonly OrderItemCostSnapshotService $orderItemCostSnapshotService,
        private readonly OrderStockValidationService $orderStockValidationService,
        private readonly InventorySalePolicyService $inventorySalePolicyService,
        private readonly InventoryIncidentService $incidentService,
        private readonly PosAuditLogService $auditLogService,
    ) {}

    public function deductForPaidOrder(Order $order, ?bool $enforceNonNegative = null): void
    {
        DB::transaction(function () use ($order, $enforceNonNegative): void {
            /** @var Order|null $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            abort_if($locked === null, Response::HTTP_NOT_FOUND, 'Order not found.');

            if ($locked->stock_deducted_at !== null) {
                return;
            }

            $locked->loadMissing('items');
            $outletId = $locked->outlet_id;
            abort_if(
                $outletId === null || (int) $outletId < 1,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Order outlet_id is required for stock deduction.'
            );

            $items = $locked->items
                ->map(fn ($item): array => [
                    'id' => $item->item_id,
                    'name' => $item->name,
                    'qty' => (float) $item->qty,
                ])
                ->values()
                ->all();

            $mode = $this->inventorySalePolicyService->getMode((int) $outletId);
            $enforce = $enforceNonNegative ?? (
                $mode === StockEnforcementMode::STRICT
                && ! $this->inventorySalePolicyService->allowsNegativeStock((int) $outletId)
            );
            $shortages = $this->orderStockValidationService->collectShortages((int) $outletId, $items);

            if ($enforce) {
                $this->orderStockValidationService->assertForSaleItems((int) $outletId, $items, $locked);
            } elseif ($shortages !== []) {
                $this->auditLogService->log(
                    'inventory.stock_sale_override',
                    'order',
                    (int) $locked->id,
                    (int) $outletId,
                    null,
                    ['shortages' => $shortages, 'orderCode' => (string) $locked->code, 'mode' => $mode]
                );
                if ($mode === StockEnforcementMode::WARNING) {
                    $this->incidentService->recordSaleShortages($locked, $shortages);
                }
            }

            $menuIds = $locked->items->pluck('item_id')->filter()->values()->all();
            if ($menuIds === []) {
                $locked->update(['stock_deducted_at' => now()]);

                return;
            }

            $recipes = DB::table('menu_recipes')
                ->whereIn('menu_item_id', $menuIds)
                ->get()
                ->groupBy('menu_item_id');

            $requiredByIngredient = [];
            foreach ($locked->items as $item) {
                if ($item->item_id === null || ! isset($recipes[$item->item_id])) {
                    continue;
                }

                foreach ($recipes[$item->item_id] as $recipe) {
                    $requiredByIngredient[$recipe->inventory_item_id] = ($requiredByIngredient[$recipe->inventory_item_id] ?? 0)
                        + ((float) $item->qty * (float) $recipe->quantity);
                }
            }

            $numericOutlet = (int) $outletId;

            foreach ($requiredByIngredient as $ingredientId => $requiredQty) {
                $ingredientId = (int) $ingredientId;
                $unitCost = $this->inventoryValuationService->getAverageCost($ingredientId, $numericOutlet);
                if ($unitCost <= 0) {
                    $unitCost = $this->inventoryCostService->resolveUnitCost($ingredientId, $numericOutlet);
                }

                try {
                    $this->ingredientOutletStockLedger->apply(
                        $numericOutlet,
                        $ingredientId,
                        'sale',
                        $requiredQty,
                        'order_payment',
                        $locked->code,
                        [
                            'cost_method' => 'moving_average',
                            'unit_cost' => $unitCost,
                            'event' => 'cogs_recognition_pending',
                            'order_id' => (int) $locked->id,
                            'order_code' => (string) $locked->code,
                        ],
                        $enforce,
                    );
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                    if ($exception->getStatusCode() !== Response::HTTP_UNPROCESSABLE_ENTITY) {
                        throw $exception;
                    }

                    throw new InsufficientStockException(
                        $this->orderStockValidationService->collectShortages($numericOutlet, $items),
                        (int) $locked->id,
                        (string) $locked->code,
                    );
                }

                $this->inventoryValuationService->recordConsumption($ingredientId, $numericOutlet, $requiredQty);
            }

            $locked->update(['stock_deducted_at' => now()]);

            $this->orderItemCostSnapshotService->snapshotForPaidOrder($locked->fresh(['items']));
        });
    }
}
