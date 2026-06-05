<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ConvertPurchaseRequestRequest;
use App\Modules\Procurement\Http\Requests\StorePurchaseRequestRequest;
use App\Modules\Procurement\Http\Requests\UpdatePurchaseRequestRequest;
use App\Modules\Procurement\Http\Resources\PurchaseRequestResource;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Services\PurchaseRequestService;
use App\Modules\Purchase\Http\Resources\PurchaseOrderResource;
use App\Modules\Purchase\Services\PurchaseScopeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PurchaseRequestController extends Controller
{
    public function __construct(
        private readonly PurchaseRequestService $purchaseRequestService,
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    public function index(): JsonResponse
    {
        $query = PurchaseRequest::query()->with(['items', 'outlet']);
        $this->purchaseScopeService->applyOutletScope(
            $query,
            request()->user('api'),
            $this->purchaseScopeService->requestedOutletIdFromRequest()
        );

        $rows = $query->latest('id')->get();

        return response()->json([
            'data' => PurchaseRequestResource::collection($rows),
        ]);
    }

    public function show(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(request()->user('api'), (int) $purchaseRequest->outlet_id);

        return response()->json([
            'data' => new PurchaseRequestResource($purchaseRequest->load(['items', 'outlet'])),
        ]);
    }

    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        $created = $this->purchaseRequestService->create($request->user('api'), $request->validated());

        return response()->json([
            'message' => 'Purchase request created successfully.',
            'data' => new PurchaseRequestResource($created),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $updated = $this->purchaseRequestService->update($purchaseRequest, $request->user('api'), $request->validated());

        return response()->json([
            'message' => 'Purchase request updated successfully.',
            'data' => new PurchaseRequestResource($updated),
        ]);
    }

    public function submit(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $updated = $this->purchaseRequestService->submit($purchaseRequest, request()->user('api'));

        return response()->json([
            'message' => 'Purchase request submitted successfully.',
            'data' => new PurchaseRequestResource($updated),
        ]);
    }

    public function approve(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $updated = $this->purchaseRequestService->approve($purchaseRequest, request()->user('api'));

        return response()->json([
            'message' => 'Purchase request approved successfully.',
            'data' => new PurchaseRequestResource($updated),
        ]);
    }

    public function reject(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $updated = $this->purchaseRequestService->reject($purchaseRequest, request()->user('api'));

        return response()->json([
            'message' => 'Purchase request rejected successfully.',
            'data' => new PurchaseRequestResource($updated),
        ]);
    }

    public function cancel(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $updated = $this->purchaseRequestService->cancel($purchaseRequest, request()->user('api'));

        return response()->json([
            'message' => 'Purchase request cancelled successfully.',
            'data' => new PurchaseRequestResource($updated),
        ]);
    }

    public function convert(ConvertPurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $po = $this->purchaseRequestService->convertToPurchaseOrder(
            $purchaseRequest,
            $request->user('api'),
            $request->validated()
        );

        return response()->json([
            'message' => 'Purchase request converted to purchase order successfully.',
            'data' => new PurchaseOrderResource($po),
            'purchaseRequest' => new PurchaseRequestResource($purchaseRequest->fresh()->load(['items', 'outlet'])),
        ]);
    }
}
