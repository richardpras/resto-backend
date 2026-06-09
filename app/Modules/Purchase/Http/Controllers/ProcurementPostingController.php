<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\ProcurementPosting;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\SupplierPayment;
use App\Modules\Purchase\Http\Resources\ProcurementPostingResource;
use App\Modules\Purchase\Services\ProcurementPostingService;
use App\Modules\Purchase\Services\PurchaseScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProcurementPostingController extends Controller
{
    public function __construct(
        private readonly ProcurementPostingService $procurementPostingService,
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->procurementPostingService->list(
            $request->user('api'),
            $this->purchaseScopeService->requestedOutletIdFromRequest(),
            is_string($request->query('sourceType')) ? $request->query('sourceType') : null,
            is_string($request->query('status')) ? $request->query('status') : null,
        );

        return response()->json([
            'data' => ProcurementPostingResource::collection($rows),
        ]);
    }

    public function show(ProcurementPosting $procurementPosting): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            request()->user('api'),
            $procurementPosting->outlet_id !== null ? (int) $procurementPosting->outlet_id : null
        );

        return response()->json([
            'data' => new ProcurementPostingResource($procurementPosting->load('journal')),
        ]);
    }

    public function postGrn(GoodsReceivingNote $goodsReceipt, Request $request): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            $request->user('api'),
            $goodsReceipt->outlet_id !== null ? (int) $goodsReceipt->outlet_id : null
        );

        $posting = $this->procurementPostingService->postGoodsReceipt($goodsReceipt, $request->user('api'));

        return response()->json([
            'message' => 'Goods receipt posted to accounting.',
            'data' => new ProcurementPostingResource($posting),
        ], Response::HTTP_CREATED);
    }

    public function postInvoice(PurchaseInvoice $purchaseInvoice, Request $request): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            $request->user('api'),
            $purchaseInvoice->outlet_id !== null ? (int) $purchaseInvoice->outlet_id : null
        );

        $posting = $this->procurementPostingService->postInvoice($purchaseInvoice, $request->user('api'));

        return response()->json([
            'message' => 'Invoice posted to accounting.',
            'data' => new ProcurementPostingResource($posting),
        ], Response::HTTP_CREATED);
    }

    public function postPayment(SupplierPayment $supplierPayment, Request $request): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            $request->user('api'),
            $supplierPayment->outlet_id !== null ? (int) $supplierPayment->outlet_id : null
        );

        $posting = $this->procurementPostingService->postPayment($supplierPayment, $request->user('api'));

        return response()->json([
            'message' => 'Supplier payment posted to accounting.',
            'data' => new ProcurementPostingResource($posting),
        ], Response::HTTP_CREATED);
    }

    public function reverse(ProcurementPosting $procurementPosting, Request $request): JsonResponse
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            $request->user('api'),
            $procurementPosting->outlet_id !== null ? (int) $procurementPosting->outlet_id : null
        );

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $posting = $this->procurementPostingService->reversePosting(
            $procurementPosting,
            $request->user('api'),
            $validated['notes'] ?? null,
        );

        return response()->json([
            'message' => 'Procurement posting reversed.',
            'data' => new ProcurementPostingResource($posting),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $sourceType = (string) $request->query('sourceType', '');
        $sourceId = (int) $request->query('sourceId', 0);
        abort_if($sourceType === '' || $sourceId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'sourceType and sourceId are required.');

        $posting = $this->procurementPostingService->getPostingStatus($sourceType, $sourceId);
        if ($posting !== null) {
            $this->purchaseScopeService->assertDocumentOutlet(
                $request->user('api'),
                $posting->outlet_id !== null ? (int) $posting->outlet_id : null
            );
        }

        return response()->json([
            'data' => $posting !== null ? new ProcurementPostingResource($posting->load('journal')) : null,
        ]);
    }
}
