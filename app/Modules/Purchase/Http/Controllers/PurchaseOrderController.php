<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\Modules\Purchase\Domain\PurchaseOrderItem;
use App\Modules\Procurement\Models\PurchaseRequestItem;
use App\Modules\Purchase\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Purchase\Http\Requests\UpdatePurchaseOrderRequest;
use App\Modules\Purchase\Http\Resources\PurchaseOrderResource;
use App\Modules\Purchase\Services\ProcurementMasterService;
use App\Modules\Purchase\Services\PurchaseAuditService;
use App\Modules\Purchase\Services\PurchaseOrderLifecycleService;
use App\Modules\Purchase\Services\PurchaseScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly PurchaseAuditService $purchaseAuditService,
        private readonly ProcurementMasterService $procurementMasterService,
        private readonly PurchaseOrderLifecycleService $purchaseOrderLifecycleService,
    ) {}

    public function index(): JsonResponse
    {
        $query = PurchaseOrder::query()->with(['items', 'purchaseRequest']);
        $this->purchaseScopeService->applyOutletScope(
            $query,
            request()->user('api'),
            $this->purchaseScopeService->requestedOutletIdFromRequest()
        );

        $rows = $query->latest('id')->get();

        return response()->json([
            'data' => PurchaseOrderResource::collection($rows),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(request()->user('api'), $purchaseOrder->outlet_id !== null ? (int) $purchaseOrder->outlet_id : null);

        return response()->json([
            'data' => new PurchaseOrderResource($purchaseOrder->load(['items', 'purchaseRequest', 'goodsReceivingNotes'])),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user('api');
        $outletId = $this->purchaseScopeService->resolveOutletId($actor, $data['outletId'] ?? $this->purchaseScopeService->requestedOutletIdFromRequest());

        $created = DB::transaction(function () use ($data, $outletId, $actor): PurchaseOrder {
            $this->procurementMasterService->validateSupplier((int) $data['supplierId']);
            $destinationWarehouseId = array_key_exists('destinationWarehouseId', $data)
                ? ($data['destinationWarehouseId'] !== null ? (int) $data['destinationWarehouseId'] : null)
                : null;
            $this->procurementMasterService->validateWarehouse($destinationWarehouseId, $outletId);

            $row = PurchaseOrder::query()->create([
                'tenant_id' => $data['tenantId'] ?? null,
                'outlet_id' => $outletId,
                'number' => $this->nextNumber(),
                'purchase_request_id' => $data['purchaseRequestId'] ?? null,
                'source_pr_id' => $data['purchaseRequestId'] ?? null,
                'supplier_id' => (int) $data['supplierId'],
                'destination_warehouse_id' => $destinationWarehouseId,
                'order_date' => $data['date'],
                'status' => 'draft',
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
                    $alreadyOrdered = (float) PurchaseOrderItem::query()
                        ->where('pr_item_id', (int) $prItemId)
                        ->where('is_from_pr', true)
                        ->sum('ordered_qty');
                    $remaining = max(0, (float) $prItem->quantity - $alreadyOrdered);
                    abort_if($orderedQty > $remaining, Response::HTTP_UNPROCESSABLE_ENTITY, 'Ordered quantity exceeds remaining PR quantity.');
                    $requestedQty = (float) $prItem->quantity;
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

            $this->purchaseAuditService->logPurchaseOrder('created', (int) $row->id, $outletId, $actor, [
                'number' => $row->number,
                'supplierId' => $row->supplier_id,
                'status' => $row->status,
            ]);

            return $row->fresh()->load(['items', 'purchaseRequest']);
        });

        return response()->json([
            'message' => 'Purchase order created successfully.',
            'data' => new PurchaseOrderResource($created),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            $request->user('api'),
            $purchaseOrder->outlet_id !== null ? (int) $purchaseOrder->outlet_id : null
        );
        abort_if($purchaseOrder->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft purchase orders can be edited.');

        $data = $request->validated();
        $actor = $request->user('api');

        $updated = DB::transaction(function () use ($data, $purchaseOrder, $actor): PurchaseOrder {
            if (array_key_exists('supplierId', $data)) {
                $this->procurementMasterService->validateSupplier((int) $data['supplierId']);
            }
            if (array_key_exists('destinationWarehouseId', $data)) {
                $warehouseId = $data['destinationWarehouseId'] !== null ? (int) $data['destinationWarehouseId'] : null;
                $this->procurementMasterService->validateWarehouse(
                    $warehouseId,
                    $purchaseOrder->outlet_id !== null ? (int) $purchaseOrder->outlet_id : null
                );
            }

            $purchaseOrder->fill([
                'purchase_request_id' => array_key_exists('purchaseRequestId', $data) ? $data['purchaseRequestId'] : $purchaseOrder->purchase_request_id,
                'source_pr_id' => array_key_exists('purchaseRequestId', $data) ? $data['purchaseRequestId'] : $purchaseOrder->source_pr_id,
                'supplier_id' => $data['supplierId'] ?? $purchaseOrder->supplier_id,
                'destination_warehouse_id' => array_key_exists('destinationWarehouseId', $data)
                    ? ($data['destinationWarehouseId'] !== null ? (int) $data['destinationWarehouseId'] : null)
                    : $purchaseOrder->destination_warehouse_id,
                'order_date' => $data['date'] ?? $purchaseOrder->order_date,
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
                        $alreadyOrdered = (float) PurchaseOrderItem::query()
                            ->where('pr_item_id', (int) $prItemId)
                            ->where('is_from_pr', true)
                            ->sum('ordered_qty');
                        $remaining = max(0, (float) $prItem->quantity - $alreadyOrdered);
                        abort_if($orderedQty > $remaining, Response::HTTP_UNPROCESSABLE_ENTITY, 'Ordered quantity exceeds remaining PR quantity.');
                        $requestedQty = (float) $prItem->quantity;
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

            $this->purchaseAuditService->logPurchaseOrder(
                'updated',
                (int) $purchaseOrder->id,
                $purchaseOrder->outlet_id !== null ? (int) $purchaseOrder->outlet_id : null,
                $actor,
                ['status' => $purchaseOrder->status]
            );

            return $purchaseOrder->fresh()->load(['items', 'purchaseRequest']);
        });

        return response()->json([
            'message' => 'Purchase order updated successfully.',
            'data' => new PurchaseOrderResource($updated),
        ]);
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            request()->user('api'),
            $purchaseOrder->outlet_id !== null ? (int) $purchaseOrder->outlet_id : null
        );
        abort_if($purchaseOrder->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft purchase orders can be deleted.');

        $actor = request()->user('api');
        $outletId = $purchaseOrder->outlet_id !== null ? (int) $purchaseOrder->outlet_id : null;

        DB::transaction(function () use ($purchaseOrder, $actor, $outletId): void {
            $this->purchaseAuditService->logPurchaseOrder('cancelled', (int) $purchaseOrder->id, $outletId, $actor, [
                'number' => $purchaseOrder->number,
            ]);
            $purchaseOrder->delete();
        });

        return response()->json([
            'message' => 'Purchase order deleted successfully.',
        ]);
    }

    public function submit(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = $this->purchaseOrderLifecycleService->submit($purchaseOrder, request()->user('api'));

        return response()->json([
            'message' => 'Purchase order submitted successfully.',
            'data' => new PurchaseOrderResource($updated),
        ]);
    }

    public function approve(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = $this->purchaseOrderLifecycleService->approve($purchaseOrder, request()->user('api'));

        return response()->json([
            'message' => 'Purchase order approved successfully.',
            'data' => new PurchaseOrderResource($updated),
        ]);
    }

    public function reject(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = $this->purchaseOrderLifecycleService->reject($purchaseOrder, request()->user('api'));

        return response()->json([
            'message' => 'Purchase order rejected successfully.',
            'data' => new PurchaseOrderResource($updated),
        ]);
    }

    public function cancel(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = $this->purchaseOrderLifecycleService->cancel($purchaseOrder, request()->user('api'));

        return response()->json([
            'message' => 'Purchase order cancelled successfully.',
            'data' => new PurchaseOrderResource($updated),
        ]);
    }

    public function close(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $updated = $this->purchaseOrderLifecycleService->close($purchaseOrder, request()->user('api'));

        return response()->json([
            'message' => 'Purchase order closed successfully.',
            'data' => new PurchaseOrderResource($updated),
        ]);
    }

    public function progress(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            request()->user('api'),
            $purchaseOrder->outlet_id !== null ? (int) $purchaseOrder->outlet_id : null
        );

        $progress = $this->purchaseOrderLifecycleService->calculateProgress($purchaseOrder->load('items'));

        return response()->json([
            'data' => array_merge($progress, [
                'status' => $purchaseOrder->status,
                'poNumber' => $purchaseOrder->number,
            ]),
        ]);
    }

    private function nextNumber(): string
    {
        $lastId = (int) (PurchaseOrder::query()->max('id') ?? 0);

        return 'PO-'.str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
    }
}
