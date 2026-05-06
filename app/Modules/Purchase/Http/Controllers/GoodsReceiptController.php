<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\GoodsReceivingNoteItem;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\Modules\Purchase\Domain\PurchaseOrderItem;
use App\Modules\Purchase\Http\Requests\StoreGoodsReceiptRequest;
use App\Modules\Purchase\Http\Resources\GoodsReceiptResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class GoodsReceiptController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = GoodsReceivingNote::query()
            ->with(['purchaseOrder', 'items.purchaseOrderItem'])
            ->latest('id')
            ->get();

        return response()->json([
            'data' => GoodsReceiptResource::collection($rows),
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $data = $request->validated();

        $created = DB::transaction(function () use ($data): GoodsReceivingNote {
            /** @var PurchaseOrder|null $purchaseOrder */
            $purchaseOrder = PurchaseOrder::query()->with('items')->lockForUpdate()->find((int) $data['purchaseOrderId']);
            abort_if($purchaseOrder === null, Response::HTTP_NOT_FOUND, 'Purchase order not found.');
            abort_if(! in_array($purchaseOrder->status, ['sent', 'partial'], true), Response::HTTP_UNPROCESSABLE_ENTITY, 'Only sent/partial PO can be received.');

            $gr = GoodsReceivingNote::query()->create([
                'purchase_order_id' => $purchaseOrder->id,
                'number' => $this->nextNumber(),
                'received_date' => $data['date'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $line) {
                $ingredientId = (int) $line['inventoryItemId'];
                $receivedQty = (float) $line['receivedQty'];

                /** @var PurchaseOrderItem|null $poItem */
                $poItem = $purchaseOrder->items->firstWhere('ingredient_id', $ingredientId);
                abort_if($poItem === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Item is not part of selected PO.');

                $remaining = (float) $poItem->ordered_qty - (float) $poItem->received_qty;
                abort_if($receivedQty > $remaining, Response::HTTP_UNPROCESSABLE_ENTITY, 'Received quantity cannot exceed remaining PO quantity.');

                GoodsReceivingNoteItem::query()->create([
                    'goods_receiving_note_id' => $gr->id,
                    'purchase_order_item_id' => $poItem->id,
                    'ingredient_id' => $ingredientId,
                    'received_qty' => $receivedQty,
                ]);

                $poItem->update([
                    'received_qty' => (float) $poItem->received_qty + $receivedQty,
                ]);

                $ingredient = Ingredient::query()->lockForUpdate()->find($ingredientId);
                abort_if($ingredient === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Ingredient not found.');
                $ingredient->update([
                    'stock' => (float) $ingredient->stock + $receivedQty,
                ]);

                StockMovement::query()->create([
                    'inventory_item_id' => $ingredientId,
                    'type' => 'purchase',
                    'quantity' => $receivedQty,
                    'source_type' => 'GR',
                    'source_id' => $gr->number,
                ]);
            }

            $purchaseOrder->refresh()->load('items');
            $allReceived = $purchaseOrder->items->every(
                static fn (PurchaseOrderItem $item): bool => (float) $item->received_qty >= (float) $item->ordered_qty
            );
            $purchaseOrder->update([
                'status' => $allReceived ? 'completed' : 'partial',
            ]);

            return $gr->fresh()->load(['purchaseOrder', 'items.purchaseOrderItem']);
        });

        return response()->json([
            'message' => 'Goods receipt created successfully.',
            'data' => new GoodsReceiptResource($created),
        ], Response::HTTP_CREATED);
    }

    private function nextNumber(): string
    {
        $lastId = (int) (GoodsReceivingNote::query()->max('id') ?? 0);
        return 'GRN-'.str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
    }
}
