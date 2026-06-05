<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\GoodsReceivingNoteItem;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\Modules\Purchase\Domain\PurchaseOrderItem;

final class ReceivingProgressService
{
    /** @return array{orderedQty:float,receivedQty:float,remainingQty:float,completionPercentage:float,items:array<int,array<string,mixed>>} */
    public function forPurchaseOrder(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing('items');

        $totalOrdered = 0.0;
        $totalReceived = 0.0;
        $items = [];

        foreach ($purchaseOrder->items as $item) {
            $line = $this->forPurchaseOrderItem($item);
            $totalOrdered += $line['orderedQty'];
            $totalReceived += $line['receivedQty'];
            $items[] = $line;
        }

        $totalRemaining = max(0, $totalOrdered - $totalReceived);
        $completionPercentage = $totalOrdered > 0
            ? round(($totalReceived / $totalOrdered) * 100, 2)
            : 0.0;

        return [
            'orderedQty' => $totalOrdered,
            'receivedQty' => $totalReceived,
            'remainingQty' => $totalRemaining,
            'completionPercentage' => $completionPercentage,
            'items' => $items,
        ];
    }

    /** @return array{orderedQty:float,receivedQty:float,remainingQty:float,completionPercentage:float,items:array<int,array<string,mixed>>} */
    public function forGoodsReceivingNote(GoodsReceivingNote $grn): array
    {
        $grn->loadMissing(['items.purchaseOrderItem', 'purchaseOrder.items']);
        $purchaseOrder = $grn->purchaseOrder;
        if ($purchaseOrder === null) {
            return [
                'orderedQty' => 0,
                'receivedQty' => 0,
                'remainingQty' => 0,
                'completionPercentage' => 0,
                'items' => [],
            ];
        }

        $poProgress = $this->forPurchaseOrder($purchaseOrder);

        return [
            'orderedQty' => $poProgress['orderedQty'],
            'receivedQty' => $poProgress['receivedQty'],
            'remainingQty' => $poProgress['remainingQty'],
            'completionPercentage' => $poProgress['completionPercentage'],
            'items' => $grn->items->map(function (GoodsReceivingNoteItem $item): array {
                $ordered = (float) ($item->purchaseOrderItem?->ordered_qty ?? 0);
                $thisGrnQty = (float) $item->received_qty;

                return [
                    'id' => (string) $item->id,
                    'inventoryItemId' => (string) $item->ingredient_id,
                    'orderedQty' => $ordered,
                    'receivedQty' => $thisGrnQty,
                    'remainingQty' => $item->purchaseOrderItem
                        ? $this->remainingQtyForPoItem($item->purchaseOrderItem, null)
                        : 0,
                ];
            })->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    public function forPurchaseOrderItem(PurchaseOrderItem $poItem): array
    {
        $ordered = (float) $poItem->ordered_qty;
        $received = (float) $poItem->received_qty;
        $remaining = max(0, $ordered - $received);

        return [
            'id' => (string) $poItem->id,
            'inventoryItemId' => (string) $poItem->ingredient_id,
            'orderedQty' => $ordered,
            'receivedQty' => $received,
            'remainingQty' => $remaining,
        ];
    }

    public function remainingQtyForPoItem(PurchaseOrderItem $poItem, ?int $excludeGrnId = null): float
    {
        $posted = (float) $poItem->received_qty;
        $pendingQuery = GoodsReceivingNoteItem::query()
            ->where('purchase_order_item_id', $poItem->id)
            ->whereHas('goodsReceivingNote', function ($query) use ($excludeGrnId): void {
                $query->whereIn('status', ['draft', 'received']);
                if ($excludeGrnId !== null) {
                    $query->where('id', '!=', $excludeGrnId);
                }
            });

        $pending = (float) $pendingQuery->sum('received_qty');

        return max(0, (float) $poItem->ordered_qty - $posted - $pending);
    }
}
