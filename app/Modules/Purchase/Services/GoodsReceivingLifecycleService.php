<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\GoodsReceivingNoteItem;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\Modules\Purchase\Domain\PurchaseOrderItem;
use App\Models\User;
use App\Modules\Inventory\Services\IngredientOutletStockLedger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class GoodsReceivingLifecycleService
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly ProcurementMasterService $procurementMasterService,
        private readonly PurchaseAuditService $purchaseAuditService,
        private readonly PurchaseOrderLifecycleService $purchaseOrderLifecycleService,
        private readonly ReceivingProgressService $receivingProgressService,
        private readonly IngredientOutletStockLedger $ingredientOutletStockLedger,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): GoodsReceivingNote
    {
        return DB::transaction(function () use ($actor, $data): GoodsReceivingNote {
            $purchaseOrder = $this->resolveReceivablePurchaseOrder($actor, (int) $data['purchaseOrderId']);
            $numericOutlet = (int) $purchaseOrder->outlet_id;

            $warehouseId = $this->resolveWarehouseId($data, $purchaseOrder, $numericOutlet);

            $gr = GoodsReceivingNote::query()->create([
                'tenant_id' => $purchaseOrder->tenant_id,
                'outlet_id' => $purchaseOrder->outlet_id,
                'purchase_order_id' => $purchaseOrder->id,
                'destination_warehouse_id' => $warehouseId,
                'warehouse_id' => $warehouseId,
                'number' => $this->nextNumber(),
                'received_date' => $data['date'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'supplier_delivery_no' => $data['supplierDeliveryNo'] ?? null,
                'supplier_delivery_date' => $data['supplierDeliveryDate'] ?? null,
                'vehicle_no' => $data['vehicleNo'] ?? null,
                'driver_name' => $data['driverName'] ?? null,
                'received_by' => $data['receivedBy'] ?? ($actor->name ?? $actor->email),
            ]);

            $this->syncItems($purchaseOrder, $gr, $data['items'], null);

            $fresh = $gr->fresh()->load(['purchaseOrder', 'items.purchaseOrderItem', 'destinationWarehouse']);
            $this->purchaseAuditService->logGoodsReceiptLifecycle('created', (int) $fresh->id, $numericOutlet, $actor, [
                'number' => $fresh->number,
                'purchaseOrderId' => $purchaseOrder->id,
            ]);

            return $fresh;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(GoodsReceivingNote $grn, User $actor, array $data): GoodsReceivingNote
    {
        $this->assertGrnOutlet($actor, $grn);
        abort_if($grn->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft goods receipts can be edited.');

        return DB::transaction(function () use ($grn, $actor, $data): GoodsReceivingNote {
            $purchaseOrder = $this->resolveReceivablePurchaseOrder($actor, (int) $grn->purchase_order_id);
            $numericOutlet = (int) $purchaseOrder->outlet_id;

            if (array_key_exists('warehouseId', $data)) {
                $warehouseId = $this->resolveWarehouseId($data, $purchaseOrder, $numericOutlet);
                $grn->warehouse_id = $warehouseId;
                $grn->destination_warehouse_id = $warehouseId;
            }

            if (array_key_exists('date', $data)) {
                $grn->received_date = $data['date'];
            }
            if (array_key_exists('notes', $data)) {
                $grn->notes = $data['notes'];
            }
            if (array_key_exists('supplierDeliveryNo', $data)) {
                $grn->supplier_delivery_no = $data['supplierDeliveryNo'];
            }
            if (array_key_exists('supplierDeliveryDate', $data)) {
                $grn->supplier_delivery_date = $data['supplierDeliveryDate'];
            }
            if (array_key_exists('vehicleNo', $data)) {
                $grn->vehicle_no = $data['vehicleNo'];
            }
            if (array_key_exists('driverName', $data)) {
                $grn->driver_name = $data['driverName'];
            }
            if (array_key_exists('receivedBy', $data)) {
                $grn->received_by = $data['receivedBy'];
            }
            $grn->save();

            if (array_key_exists('items', $data)) {
                $grn->items()->delete();
                $this->syncItems($purchaseOrder, $grn, $data['items'], (int) $grn->id);
            }

            return $grn->fresh()->load(['purchaseOrder', 'items.purchaseOrderItem', 'destinationWarehouse']);
        });
    }

    public function receive(GoodsReceivingNote $grn, User $actor): GoodsReceivingNote
    {
        $this->assertGrnOutlet($actor, $grn);
        abort_if($grn->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft goods receipts can be received.');
        $grn->loadCount('items');
        abort_if($grn->items_count < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'Cannot receive a goods receipt without items.');
        abort_if($grn->warehouse_id === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Warehouse is required before receiving.');

        return DB::transaction(function () use ($grn, $actor): GoodsReceivingNote {
            $grn->update([
                'status' => 'received',
                'received_at' => now(),
            ]);

            $fresh = $grn->fresh()->load(['purchaseOrder', 'items.purchaseOrderItem', 'destinationWarehouse']);
            $this->purchaseAuditService->logGoodsReceiptLifecycle('received', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'number' => $fresh->number,
            ]);

            return $fresh;
        });
    }

    public function post(GoodsReceivingNote $grn, User $actor): GoodsReceivingNote
    {
        $this->assertGrnOutlet($actor, $grn);
        abort_if($grn->status !== 'received', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only received goods receipts can be posted.');

        return DB::transaction(function () use ($grn, $actor): GoodsReceivingNote {
            $purchaseOrder = PurchaseOrder::query()->with('items')->lockForUpdate()->find((int) $grn->purchase_order_id);
            abort_if($purchaseOrder === null, Response::HTTP_NOT_FOUND, 'Purchase order not found.');
            $numericOutlet = (int) $purchaseOrder->outlet_id;

            $grn->load('items.purchaseOrderItem');

            foreach ($grn->items as $line) {
                $ingredientId = (int) $line->ingredient_id;
                $receivedQty = (float) $line->received_qty;

                /** @var PurchaseOrderItem|null $poItem */
                $poItem = $purchaseOrder->items->firstWhere('id', $line->purchase_order_item_id)
                    ?? $purchaseOrder->items->firstWhere('ingredient_id', $ingredientId);
                abort_if($poItem === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Item is not part of selected PO.');

                $remaining = $this->receivingProgressService->remainingQtyForPoItem($poItem, (int) $grn->id);
                abort_if($receivedQty > $remaining, Response::HTTP_UNPROCESSABLE_ENTITY, 'Quantity exceeds remaining quantity.');

                $originalPoCost = (float) ($line->original_po_cost ?? $poItem->unit_price);
                $actualReceivedCost = (float) ($line->actual_received_cost ?? $originalPoCost);

                $poItem->update([
                    'received_qty' => (float) $poItem->received_qty + $receivedQty,
                ]);

                $this->ingredientOutletStockLedger->apply(
                    $numericOutlet,
                    $ingredientId,
                    'purchase',
                    $receivedQty,
                    'GR',
                    $grn->number,
                    [
                        'cost_method' => 'moving_average_ready',
                        'unit_cost' => $actualReceivedCost,
                        'original_po_cost' => $originalPoCost,
                        'event' => 'purchase_grn',
                    ],
                );
            }

            $grn->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $actor->id,
            ]);

            $this->purchaseOrderLifecycleService->recalculateReceivingProgress($purchaseOrder->refresh(), $actor);

            $fresh = $grn->fresh()->load(['purchaseOrder', 'items.purchaseOrderItem', 'destinationWarehouse']);
            $this->purchaseAuditService->logGoodsReceiptLifecycle('posted', (int) $fresh->id, $numericOutlet, $actor, [
                'number' => $fresh->number,
                'purchaseOrderId' => $purchaseOrder->id,
            ]);

            return $fresh;
        });
    }

    public function cancel(GoodsReceivingNote $grn, User $actor): GoodsReceivingNote
    {
        $this->assertGrnOutlet($actor, $grn);
        abort_if($grn->status === 'posted', Response::HTTP_UNPROCESSABLE_ENTITY, 'Posted goods receipts cannot be cancelled.');
        abort_if(! in_array($grn->status, ['draft', 'received'], true), Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft or received goods receipts can be cancelled.');

        return DB::transaction(function () use ($grn, $actor): GoodsReceivingNote {
            $grn->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ]);

            $fresh = $grn->fresh()->load(['purchaseOrder', 'items.purchaseOrderItem', 'destinationWarehouse']);
            $this->purchaseAuditService->logGoodsReceiptLifecycle('cancelled', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'number' => $fresh->number,
            ]);

            return $fresh;
        });
    }

    public function destroy(GoodsReceivingNote $grn, User $actor): void
    {
        $this->assertGrnOutlet($actor, $grn);
        abort_if($grn->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft goods receipts can be deleted.');

        DB::transaction(function () use ($grn): void {
            $grn->items()->delete();
            $grn->delete();
        });
    }

    public function recalculatePoProgress(PurchaseOrder $purchaseOrder, ?User $actor = null): PurchaseOrder
    {
        return $this->purchaseOrderLifecycleService->recalculateReceivingProgress($purchaseOrder, $actor);
    }

    private function resolveReceivablePurchaseOrder(User $actor, int $purchaseOrderId): PurchaseOrder
    {
        /** @var PurchaseOrder|null $purchaseOrder */
        $purchaseOrder = PurchaseOrder::query()->with('items')->lockForUpdate()->find($purchaseOrderId);
        abort_if($purchaseOrder === null, Response::HTTP_NOT_FOUND, 'Purchase order not found.');
        abort_if(! in_array($purchaseOrder->status, ['approved', 'partially_received'], true), Response::HTTP_UNPROCESSABLE_ENTITY, 'Only approved or partially received PO can be received.');
        abort_if(
            $purchaseOrder->outlet_id === null || (int) $purchaseOrder->outlet_id < 1,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Purchase order outlet_id is required to receive stock.'
        );
        $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $purchaseOrder->outlet_id);

        return $purchaseOrder;
    }

    /** @param array<string,mixed> $data */
    private function resolveWarehouseId(array $data, PurchaseOrder $purchaseOrder, int $outletId): int
    {
        $warehouseId = null;
        if (array_key_exists('warehouseId', $data) && $data['warehouseId'] !== null) {
            $warehouseId = (int) $data['warehouseId'];
        } elseif (array_key_exists('destinationWarehouseId', $data) && $data['destinationWarehouseId'] !== null) {
            $warehouseId = (int) $data['destinationWarehouseId'];
        } elseif ($purchaseOrder->destination_warehouse_id !== null) {
            $warehouseId = (int) $purchaseOrder->destination_warehouse_id;
        }

        abort_if($warehouseId === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'warehouse_id is required.');

        $warehouse = $this->procurementMasterService->validateWarehouse($warehouseId, $outletId);
        abort_if(
            $warehouse !== null && $warehouse->outlet_id !== null && (int) $warehouse->outlet_id !== $outletId,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Warehouse does not belong to outlet.'
        );

        return $warehouseId;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function syncItems(PurchaseOrder $purchaseOrder, GoodsReceivingNote $grn, array $items, ?int $excludeGrnId): void
    {
        foreach ($items as $line) {
            $ingredientId = (int) $line['inventoryItemId'];
            $receivedQty = (float) $line['receivedQty'];

            /** @var PurchaseOrderItem|null $poItem */
            $poItem = $purchaseOrder->items->firstWhere('ingredient_id', $ingredientId);
            abort_if($poItem === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Item is not part of selected PO.');

            $remaining = $this->receivingProgressService->remainingQtyForPoItem($poItem, $excludeGrnId);
            abort_if($receivedQty > $remaining, Response::HTTP_UNPROCESSABLE_ENTITY, 'Quantity exceeds remaining quantity.');

            $originalPoCost = (float) $poItem->unit_price;
            $actualReceivedCost = array_key_exists('unitCost', $line) && $line['unitCost'] !== null
                ? (float) $line['unitCost']
                : $originalPoCost;

            GoodsReceivingNoteItem::query()->create([
                'goods_receiving_note_id' => $grn->id,
                'purchase_order_item_id' => $poItem->id,
                'ingredient_id' => $ingredientId,
                'received_qty' => $receivedQty,
                'original_po_cost' => $originalPoCost,
                'actual_received_cost' => $actualReceivedCost,
            ]);
        }
    }

    private function assertGrnOutlet(User $actor, GoodsReceivingNote $grn): void
    {
        abort_if(
            $grn->outlet_id === null || (int) $grn->outlet_id < 1,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Goods receipt outlet_id is required.'
        );
        $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $grn->outlet_id);
    }

    private function nextNumber(): string
    {
        $lastId = (int) (GoodsReceivingNote::query()->max('id') ?? 0);

        return 'GRN-'.str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
    }
}
