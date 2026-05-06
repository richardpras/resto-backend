<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\Modules\Purchase\Domain\PurchaseOrderItem;
use App\Models\Modules\Purchase\Domain\PurchaseRequestItem;
use App\Modules\Purchase\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Purchase\Http\Requests\UpdatePurchaseOrderRequest;
use App\Modules\Purchase\Http\Resources\PurchaseOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = PurchaseOrder::query()
            ->with(['items', 'purchaseRequest'])
            ->latest('id')
            ->get();

        return response()->json([
            'data' => PurchaseOrderResource::collection($rows),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json([
            'data' => new PurchaseOrderResource($purchaseOrder->load(['items', 'purchaseRequest'])),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $created = DB::transaction(function () use ($data): PurchaseOrder {
            $row = PurchaseOrder::query()->create([
                'number' => $this->nextNumber(),
                'purchase_request_id' => $data['purchaseRequestId'] ?? null,
                'source_pr_id' => $data['purchaseRequestId'] ?? null,
                'supplier_id' => (int) $data['supplierId'],
                'order_date' => $data['date'],
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $isFromPr = (bool) ($item['isFromPr'] ?? false);
                $prItemId = $item['prItemId'] ?? null;
                $orderedQty = (float) $item['qty'];
                $requestedQty = (float) ($item['requestedQty'] ?? 0);

                if ($isFromPr && $prItemId) {
                    /** @var PurchaseRequestItem|null $prItem */
                    $prItem = PurchaseRequestItem::query()->lockForUpdate()->find((int) $prItemId);
                    abort_if($prItem === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'PR item not found.');
                    $remaining = max(0, (float) $prItem->requested_qty - (float) ($prItem->fulfilled_qty ?? 0));
                    abort_if($orderedQty > $remaining, Response::HTTP_UNPROCESSABLE_ENTITY, 'Ordered quantity exceeds remaining PR quantity.');
                    $prItem->update([
                        'fulfilled_qty' => (float) ($prItem->fulfilled_qty ?? 0) + $orderedQty,
                    ]);
                    $requestedQty = (float) $prItem->requested_qty;
                }

                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $row->id,
                    'pr_item_id' => $prItemId ? (int) $prItemId : null,
                    'ingredient_id' => (int) $item['inventoryItemId'],
                    'ordered_qty' => $orderedQty,
                    'requested_qty' => $requestedQty,
                    'is_from_pr' => $isFromPr,
                    'received_qty' => 0,
                    'unit_price' => (float) $item['price'],
                ]);
            }

            return $row->fresh()->load(['items', 'purchaseRequest']);
        });

        return response()->json([
            'message' => 'Purchase order created successfully.',
            'data' => new PurchaseOrderResource($created),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validated();
        $updated = DB::transaction(function () use ($data, $purchaseOrder): PurchaseOrder {
            // rollback previous fulfilled quantities from PR-linked lines
            $existingItems = $purchaseOrder->items()->lockForUpdate()->get();
            foreach ($existingItems as $existingItem) {
                if (! $existingItem->pr_item_id || ! $existingItem->is_from_pr) {
                    continue;
                }
                $prItem = PurchaseRequestItem::query()->lockForUpdate()->find((int) $existingItem->pr_item_id);
                if ($prItem) {
                    $prItem->update([
                        'fulfilled_qty' => max(0, (float) ($prItem->fulfilled_qty ?? 0) - (float) $existingItem->ordered_qty),
                    ]);
                }
            }

            $purchaseOrder->fill([
                'purchase_request_id' => array_key_exists('purchaseRequestId', $data) ? $data['purchaseRequestId'] : $purchaseOrder->purchase_request_id,
                'source_pr_id' => array_key_exists('purchaseRequestId', $data) ? $data['purchaseRequestId'] : $purchaseOrder->source_pr_id,
                'supplier_id' => $data['supplierId'] ?? $purchaseOrder->supplier_id,
                'order_date' => $data['date'] ?? $purchaseOrder->order_date,
                'status' => $data['status'] ?? $purchaseOrder->status,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $purchaseOrder->notes,
            ]);
            $purchaseOrder->save();

            if (array_key_exists('items', $data)) {
                $purchaseOrder->items()->delete();
                foreach ($data['items'] as $item) {
                    $isFromPr = (bool) ($item['isFromPr'] ?? false);
                    $prItemId = $item['prItemId'] ?? null;
                    $orderedQty = (float) $item['qty'];
                    $requestedQty = (float) ($item['requestedQty'] ?? 0);

                    if ($isFromPr && $prItemId) {
                        /** @var PurchaseRequestItem|null $prItem */
                        $prItem = PurchaseRequestItem::query()->lockForUpdate()->find((int) $prItemId);
                        abort_if($prItem === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'PR item not found.');
                        $remaining = max(0, (float) $prItem->requested_qty - (float) ($prItem->fulfilled_qty ?? 0));
                        abort_if($orderedQty > $remaining, Response::HTTP_UNPROCESSABLE_ENTITY, 'Ordered quantity exceeds remaining PR quantity.');
                        $prItem->update([
                            'fulfilled_qty' => (float) ($prItem->fulfilled_qty ?? 0) + $orderedQty,
                        ]);
                        $requestedQty = (float) $prItem->requested_qty;
                    }

                    PurchaseOrderItem::query()->create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'pr_item_id' => $prItemId ? (int) $prItemId : null,
                        'ingredient_id' => (int) $item['inventoryItemId'],
                        'ordered_qty' => $orderedQty,
                        'requested_qty' => $requestedQty,
                        'is_from_pr' => $isFromPr,
                        'received_qty' => 0,
                        'unit_price' => (float) $item['price'],
                    ]);
                }
            }

            return $purchaseOrder->fresh()->load(['items', 'purchaseRequest']);
        });

        return response()->json([
            'message' => 'Purchase order updated successfully.',
            'data' => new PurchaseOrderResource($updated),
        ]);
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->delete();

        return response()->json([
            'message' => 'Purchase order deleted successfully.',
        ]);
    }

    private function nextNumber(): string
    {
        $lastId = (int) (PurchaseOrder::query()->max('id') ?? 0);
        return 'PO-'.str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
    }
}
