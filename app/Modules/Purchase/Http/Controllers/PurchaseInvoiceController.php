<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Modules\Purchase\Http\Requests\StorePurchaseInvoicePaymentRequest;
use App\Modules\Purchase\Http\Requests\StorePurchaseInvoiceRequest;
use App\Modules\Purchase\Http\Requests\UpdatePurchaseInvoiceRequest;
use App\Modules\Purchase\Http\Resources\PurchaseInvoiceResource;
use App\Modules\Purchase\Services\PurchaseAuditService;
use App\Modules\Purchase\Services\PurchaseScopeService;
use App\Modules\Purchase\Services\SupplierInvoiceService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private readonly SupplierInvoiceService $supplierInvoiceService,
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly PurchaseAuditService $purchaseAuditService,
    ) {}

    public function index(): JsonResponse
    {
        $query = PurchaseInvoice::query()->with(['purchaseOrder.items', 'goodsReceivingNote', 'payments', 'items', 'supplier', 'latestMatchResult']);
        $this->purchaseScopeService->applyOutletScope(
            $query,
            request()->user('api'),
            $this->purchaseScopeService->requestedOutletIdFromRequest()
        );

        $rows = $query->latest('id')->get();

        return response()->json([
            'data' => PurchaseInvoiceResource::collection($rows),
        ]);
    }

    public function show(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            request()->user('api'),
            $purchaseInvoice->outlet_id !== null ? (int) $purchaseInvoice->outlet_id : null
        );

        return response()->json([
            'data' => new PurchaseInvoiceResource($purchaseInvoice->load(['purchaseOrder.items', 'goodsReceivingNote', 'payments', 'items', 'supplier', 'latestMatchResult'])),
        ]);
    }

    public function store(StorePurchaseInvoiceRequest $request): JsonResponse
    {
        $created = $this->supplierInvoiceService->create($request->user('api'), $request->validated());

        return response()->json([
            'message' => 'Purchase invoice created successfully.',
            'data' => new PurchaseInvoiceResource($created),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        if ($request->has('status') && count($request->validated()) === 1) {
            return $this->legacyStatusUpdate($request, $purchaseInvoice);
        }

        $updated = $this->supplierInvoiceService->update($purchaseInvoice, $request->user('api'), $request->validated());

        return response()->json([
            'message' => 'Purchase invoice updated successfully.',
            'data' => new PurchaseInvoiceResource($updated),
        ]);
    }

    public function submit(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $updated = $this->supplierInvoiceService->submit($purchaseInvoice, request()->user('api'));

        return response()->json([
            'message' => 'Purchase invoice submitted successfully.',
            'data' => new PurchaseInvoiceResource($updated),
        ]);
    }

    public function approve(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $updated = $this->supplierInvoiceService->approve($purchaseInvoice, request()->user('api'));

        return response()->json([
            'message' => 'Purchase invoice approved successfully.',
            'data' => new PurchaseInvoiceResource($updated),
        ]);
    }

    public function void(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $updated = $this->supplierInvoiceService->void($purchaseInvoice, request()->user('api'));

        return response()->json([
            'message' => 'Purchase invoice voided successfully.',
            'data' => new PurchaseInvoiceResource($updated),
        ]);
    }

    public function outstanding(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            request()->user('api'),
            $purchaseInvoice->outlet_id !== null ? (int) $purchaseInvoice->outlet_id : null
        );

        return response()->json([
            'data' => $this->supplierInvoiceService->outstandingDetails($purchaseInvoice),
        ]);
    }

    public function addPayment(StorePurchaseInvoicePaymentRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Use POST /api/v1/supplier-payments for supplier payment allocation.');
    }

    private function legacyStatusUpdate(UpdatePurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            $request->user('api'),
            $purchaseInvoice->outlet_id !== null ? (int) $purchaseInvoice->outlet_id : null
        );

        $previousStatus = $purchaseInvoice->status;
        $newStatus = match ($request->validated()['status']) {
            'unpaid' => 'approved',
            'partial' => 'partially_paid',
            'paid' => 'paid',
            default => $request->validated()['status'],
        };

        $purchaseInvoice->update(['status' => $newStatus]);
        $this->purchaseAuditService->logPurchaseInvoice(
            'updated',
            (int) $purchaseInvoice->id,
            $purchaseInvoice->outlet_id !== null ? (int) $purchaseInvoice->outlet_id : null,
            $request->user('api'),
            ['status' => $purchaseInvoice->status, 'previousStatus' => $previousStatus]
        );

        return response()->json([
            'message' => 'Purchase invoice updated successfully.',
            'data' => new PurchaseInvoiceResource($purchaseInvoice->fresh()->load(['purchaseOrder.items', 'goodsReceivingNote', 'payments', 'items', 'supplier', 'latestMatchResult'])),
        ]);
    }
}
