<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Purchase\Domain\SupplierPayment;
use App\Modules\Purchase\Http\Requests\StoreSupplierPaymentRequest;
use App\Modules\Purchase\Http\Requests\UpdateSupplierPaymentRequest;
use App\Modules\Purchase\Http\Resources\SupplierPaymentResource;
use App\Modules\Purchase\Services\AccountsPayableAgingService;
use App\Modules\Purchase\Services\PurchaseScopeService;
use App\Modules\Purchase\Services\SupplierPaymentService;
use App\Modules\Purchase\Services\SupplierStatementService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SupplierPaymentController extends Controller
{
    public function __construct(
        private readonly SupplierPaymentService $supplierPaymentService,
        private readonly AccountsPayableAgingService $accountsPayableAgingService,
        private readonly SupplierStatementService $supplierStatementService,
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    public function index(): JsonResponse
    {
        $query = SupplierPayment::query()->with(['supplier', 'allocations.purchaseInvoice']);
        $this->purchaseScopeService->applyOutletScope(
            $query,
            request()->user('api'),
            $this->purchaseScopeService->requestedOutletIdFromRequest()
        );

        return response()->json([
            'data' => SupplierPaymentResource::collection($query->latest('id')->get()),
        ]);
    }

    public function show(SupplierPayment $supplierPayment): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            request()->user('api'),
            $supplierPayment->outlet_id !== null ? (int) $supplierPayment->outlet_id : null
        );

        return response()->json([
            'data' => new SupplierPaymentResource($supplierPayment->load(['supplier', 'allocations.purchaseInvoice'])),
        ]);
    }

    public function store(StoreSupplierPaymentRequest $request): JsonResponse
    {
        $created = $this->supplierPaymentService->create($request->user('api'), $request->validated());

        return response()->json([
            'message' => 'Supplier payment created successfully.',
            'data' => new SupplierPaymentResource($created),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateSupplierPaymentRequest $request, SupplierPayment $supplierPayment): JsonResponse
    {
        $updated = $this->supplierPaymentService->update($supplierPayment, $request->user('api'), $request->validated());

        return response()->json([
            'message' => 'Supplier payment updated successfully.',
            'data' => new SupplierPaymentResource($updated),
        ]);
    }

    public function approve(SupplierPayment $supplierPayment): JsonResponse
    {
        $updated = $this->supplierPaymentService->approve($supplierPayment, request()->user('api'));

        return response()->json([
            'message' => 'Supplier payment approved successfully.',
            'data' => new SupplierPaymentResource($updated),
        ]);
    }

    public function post(SupplierPayment $supplierPayment): JsonResponse
    {
        $updated = $this->supplierPaymentService->post($supplierPayment, request()->user('api'));

        return response()->json([
            'message' => 'Supplier payment posted successfully.',
            'data' => new SupplierPaymentResource($updated),
        ]);
    }

    public function void(SupplierPayment $supplierPayment): JsonResponse
    {
        $updated = $this->supplierPaymentService->void($supplierPayment, request()->user('api'));

        return response()->json([
            'message' => 'Supplier payment voided successfully.',
            'data' => new SupplierPaymentResource($updated),
        ]);
    }

    public function apAging(): JsonResponse
    {
        return response()->json([
            'data' => $this->accountsPayableAgingService->report(
                request()->user('api'),
                $this->purchaseScopeService->requestedOutletIdFromRequest()
            ),
        ]);
    }

    public function supplierStatement(): JsonResponse
    {
        $supplierId = (int) request()->query('supplierId');
        abort_if($supplierId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'supplierId is required.');

        return response()->json([
            'data' => $this->supplierStatementService->statement(
                request()->user('api'),
                $this->purchaseScopeService->requestedOutletIdFromRequest(),
                $supplierId,
                request()->query('fromDate'),
                request()->query('toDate'),
            ),
        ]);
    }
}
