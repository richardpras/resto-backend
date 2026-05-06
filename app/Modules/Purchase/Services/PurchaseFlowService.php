<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\GoodsReceivingNoteItem;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\Modules\Purchase\Domain\PurchaseOrderItem;
use App\Models\Modules\Purchase\Domain\PurchaseRequest;
use App\Models\Modules\Purchase\Domain\PurchaseRequestItem;
use App\Modules\Inventory\Services\IngredientOutletStockLedger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PurchaseFlowService
{
    public function __construct(
        private readonly IngredientOutletStockLedger $ingredientOutletStockLedger,
    ) {}

    public function createPurchaseRequest(array $header, array $items): PurchaseRequest
    {
        return DB::transaction(function () use ($header, $items) {
            $pr = PurchaseRequest::query()->create([
                'tenant_id' => $header['tenant_id'] ?? null,
                'outlet_id' => $header['outlet_id'] ?? null,
                'number' => $header['number'],
                'status' => $header['status'] ?? 'approved',
                'request_date' => $header['request_date'],
                'notes' => $header['notes'] ?? null,
            ]);

            foreach ($items as $item) {
                PurchaseRequestItem::query()->create([
                    'purchase_request_id' => $pr->id,
                    'ingredient_id' => $item['ingredient_id'],
                    'requested_qty' => $item['requested_qty'],
                    'unit' => $item['unit'] ?? null,
                ]);
            }

            return $pr->load('items');
        });
    }

    public function createPurchaseOrderFromRequest(int $purchaseRequestId, array $header): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseRequestId, $header) {
            $pr = PurchaseRequest::query()->with('items')->find($purchaseRequestId);
            abort_if($pr === null, Response::HTTP_NOT_FOUND, 'Purchase request not found.');
            abort_if($pr->status !== 'approved', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only approved PR can be converted to PO.');
            abort_if($pr->purchaseOrder()->exists(), Response::HTTP_UNPROCESSABLE_ENTITY, 'PO already exists for this PR.');

            $po = PurchaseOrder::query()->create([
                'tenant_id' => $pr->tenant_id,
                'outlet_id' => $pr->outlet_id,
                'purchase_request_id' => $pr->id,
                'number' => $header['number'],
                'status' => 'open',
                'order_date' => $header['order_date'],
                'supplier_name' => $header['supplier_name'] ?? null,
            ]);

            foreach ($pr->items as $prItem) {
                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $po->id,
                    'ingredient_id' => $prItem->ingredient_id,
                    'ordered_qty' => $prItem->requested_qty,
                    'received_qty' => 0,
                    'unit_price' => $header['unit_price_map'][$prItem->ingredient_id] ?? 0,
                ]);
            }

            return $po->load('items');
        });
    }

    public function postGoodsReceivingNote(int $purchaseOrderId, array $header, array $items): GoodsReceivingNote
    {
        return DB::transaction(function () use ($purchaseOrderId, $header, $items) {
            $po = PurchaseOrder::query()->with('items')->find($purchaseOrderId);
            abort_if($po === null, Response::HTTP_NOT_FOUND, 'Purchase order not found.');
            abort_if(! in_array($po->status, ['open', 'partially_received'], true), Response::HTTP_UNPROCESSABLE_ENTITY, 'PO cannot be received in its current status.');

            abort_if(
                $po->outlet_id === null || (int) $po->outlet_id < 1,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Purchase order outlet_id is required to receive stock.'
            );

            $grn = GoodsReceivingNote::query()->create([
                'tenant_id' => $po->tenant_id,
                'outlet_id' => $po->outlet_id,
                'purchase_order_id' => $po->id,
                'number' => $header['number'],
                'received_date' => $header['received_date'],
                'notes' => $header['notes'] ?? null,
            ]);

            $numericOutlet = (int) $po->outlet_id;

            foreach ($items as $line) {
                /** @var PurchaseOrderItem|null $poItem */
                $poItem = $po->items->firstWhere('id', (int) $line['purchase_order_item_id']);
                abort_if($poItem === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'GRN line references invalid PO item.');

                $receiveQty = (float) $line['received_qty'];
                $remainingQty = (float) $poItem->ordered_qty - (float) $poItem->received_qty;
                abort_if($receiveQty <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Received quantity must be greater than zero.');
                abort_if($receiveQty > $remainingQty, Response::HTTP_UNPROCESSABLE_ENTITY, 'Over-receiving is not allowed.');

                GoodsReceivingNoteItem::query()->create([
                    'goods_receiving_note_id' => $grn->id,
                    'purchase_order_item_id' => $poItem->id,
                    'ingredient_id' => $poItem->ingredient_id,
                    'received_qty' => $receiveQty,
                ]);

                $poItem->update([
                    'received_qty' => (float) $poItem->received_qty + $receiveQty,
                ]);

                $this->ingredientOutletStockLedger->apply(
                    $numericOutlet,
                    (int) $poItem->ingredient_id,
                    'purchase',
                    $receiveQty,
                    'purchase_grn',
                    $grn->number,
                );
            }

            $po->refresh()->load('items');
            $isFullyReceived = $po->items->every(
                static fn (PurchaseOrderItem $item): bool => (float) $item->received_qty >= (float) $item->ordered_qty
            );
            $po->update(['status' => $isFullyReceived ? 'fully_received' : 'partially_received']);

            return $grn->load('items');
        });
    }

    public function createInvoiceFromGrn(int $purchaseOrderId, int $grnId, array $header): PurchaseInvoice
    {
        return DB::transaction(function () use ($purchaseOrderId, $grnId, $header) {
            $po = PurchaseOrder::query()->find($purchaseOrderId);
            abort_if($po === null, Response::HTTP_NOT_FOUND, 'Purchase order not found.');
            abort_if(! in_array($po->status, ['partially_received', 'fully_received'], true), Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice requires existing GRN posting.');

            $grn = GoodsReceivingNote::query()->find($grnId);
            abort_if($grn === null || $grn->purchase_order_id !== $po->id, Response::HTTP_UNPROCESSABLE_ENTITY, 'GRN is invalid for this PO.');

            return PurchaseInvoice::query()->create([
                'tenant_id' => $po->tenant_id,
                'outlet_id' => $po->outlet_id,
                'purchase_order_id' => $po->id,
                'goods_receiving_note_id' => $grn->id,
                'number' => $header['number'],
                'invoice_date' => $header['invoice_date'],
                'total' => $header['total'] ?? 0,
            ]);
        });
    }
}
