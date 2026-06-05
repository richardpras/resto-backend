<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Modules\Purchase\Http\Requests\StoreGoodsReceiptRequest;
use App\Modules\Purchase\Http\Requests\UpdateGoodsReceiptRequest;
use App\Modules\Purchase\Http\Resources\GoodsReceiptResource;
use App\Modules\Purchase\Services\GoodsReceivingLifecycleService;
use App\Modules\Purchase\Services\PurchaseScopeService;
use App\Modules\Purchase\Services\ReceivingProgressService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceivingLifecycleService $goodsReceivingLifecycleService,
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly ReceivingProgressService $receivingProgressService,
    ) {}

    public function index(): JsonResponse
    {
        $query = GoodsReceivingNote::query()->with(['purchaseOrder', 'items.purchaseOrderItem', 'invoice']);
        $this->purchaseScopeService->applyOutletScope(
            $query,
            request()->user('api'),
            $this->purchaseScopeService->requestedOutletIdFromRequest()
        );

        $rows = $query->latest('id')->get();

        return response()->json([
            'data' => GoodsReceiptResource::collection($rows),
        ]);
    }

    public function show(GoodsReceivingNote $goodsReceipt): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            request()->user('api'),
            $goodsReceipt->outlet_id !== null ? (int) $goodsReceipt->outlet_id : null
        );

        return response()->json([
            'data' => new GoodsReceiptResource($goodsReceipt->load(['purchaseOrder', 'items.purchaseOrderItem', 'invoice', 'warehouse'])),
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $created = $this->goodsReceivingLifecycleService->create($request->user('api'), $request->validated());

        return response()->json([
            'message' => 'Goods receipt created successfully.',
            'data' => new GoodsReceiptResource($created),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceivingNote $goodsReceipt): JsonResponse
    {
        $updated = $this->goodsReceivingLifecycleService->update($goodsReceipt, $request->user('api'), $request->validated());

        return response()->json([
            'message' => 'Goods receipt updated successfully.',
            'data' => new GoodsReceiptResource($updated),
        ]);
    }

    public function destroy(GoodsReceivingNote $goodsReceipt): JsonResponse
    {
        $this->goodsReceivingLifecycleService->destroy($goodsReceipt, request()->user('api'));

        return response()->json([
            'message' => 'Goods receipt deleted successfully.',
        ]);
    }

    public function receive(GoodsReceivingNote $goodsReceipt): JsonResponse
    {
        $updated = $this->goodsReceivingLifecycleService->receive($goodsReceipt, request()->user('api'));

        return response()->json([
            'message' => 'Goods receipt marked as received.',
            'data' => new GoodsReceiptResource($updated),
        ]);
    }

    public function post(GoodsReceivingNote $goodsReceipt): JsonResponse
    {
        $updated = $this->goodsReceivingLifecycleService->post($goodsReceipt, request()->user('api'));

        return response()->json([
            'message' => 'Goods receipt posted to inventory.',
            'data' => new GoodsReceiptResource($updated),
        ]);
    }

    public function cancel(GoodsReceivingNote $goodsReceipt): JsonResponse
    {
        $updated = $this->goodsReceivingLifecycleService->cancel($goodsReceipt, request()->user('api'));

        return response()->json([
            'message' => 'Goods receipt cancelled.',
            'data' => new GoodsReceiptResource($updated),
        ]);
    }

    public function progress(GoodsReceivingNote $goodsReceipt): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            request()->user('api'),
            $goodsReceipt->outlet_id !== null ? (int) $goodsReceipt->outlet_id : null
        );

        $progress = $this->receivingProgressService->forGoodsReceivingNote($goodsReceipt->load(['items.purchaseOrderItem', 'purchaseOrder.items']));

        return response()->json([
            'data' => $progress,
        ]);
    }
}
