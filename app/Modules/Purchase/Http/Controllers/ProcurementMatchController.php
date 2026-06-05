<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Purchase\Domain\ProcurementMatchConfig;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Modules\Purchase\Http\Resources\ProcurementMatchConfigResource;
use App\Modules\Purchase\Http\Resources\ProcurementMatchResultResource;
use App\Modules\Purchase\Services\ProcurementMatchConfigService;
use App\Modules\Purchase\Services\PurchaseScopeService;
use App\Modules\Purchase\Services\ThreeWayMatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProcurementMatchController extends Controller
{
    public function __construct(
        private readonly ThreeWayMatchService $threeWayMatchService,
        private readonly ProcurementMatchConfigService $procurementMatchConfigService,
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    public function indexResults(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $results = $this->threeWayMatchService->listLatestResults(
            $request->user('api'),
            $this->purchaseScopeService->requestedOutletIdFromRequest(),
            is_string($status) ? $status : null,
        );

        return response()->json([
            'data' => ProcurementMatchResultResource::collection($results),
        ]);
    }

    public function showResult(int $invoiceId): JsonResponse
    {
        $invoice = PurchaseInvoice::query()->findOrFail($invoiceId);
        $this->purchaseScopeService->assertDocumentOutlet(
            request()->user('api'),
            $invoice->outlet_id !== null ? (int) $invoice->outlet_id : null
        );

        $result = $this->threeWayMatchService->latestResultForInvoice($invoiceId);
        abort_if($result === null, Response::HTTP_NOT_FOUND, 'Match result not found.');

        $result->load(['purchaseOrder', 'goodsReceivingNote', 'purchaseInvoice']);

        return response()->json([
            'data' => new ProcurementMatchResultResource($result),
        ]);
    }

    public function revalidate(Request $request): JsonResponse
    {
        $invoiceId = (int) ($request->input('invoiceId') ?? 0);
        abort_if($invoiceId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice is required.');

        $invoice = PurchaseInvoice::query()->findOrFail($invoiceId);
        $this->purchaseScopeService->assertDocumentOutlet(
            $request->user('api'),
            $invoice->outlet_id !== null ? (int) $invoice->outlet_id : null
        );

        $result = $this->threeWayMatchService->validateInvoice($invoice, $request->user('api'), true);
        $result->load(['purchaseOrder', 'goodsReceivingNote', 'purchaseInvoice']);

        return response()->json([
            'message' => 'Match revalidated.',
            'data' => new ProcurementMatchResultResource($result),
        ]);
    }

    public function indexConfigs(): JsonResponse
    {
        $configs = $this->procurementMatchConfigService->list(
            request()->user('api'),
            $this->purchaseScopeService->requestedOutletIdFromRequest(),
        );

        return response()->json([
            'data' => ProcurementMatchConfigResource::collection($configs),
        ]);
    }

    public function storeConfig(Request $request): JsonResponse
    {
        $config = $this->procurementMatchConfigService->create(
            $request->user('api'),
            $request->all(),
        );

        return response()->json([
            'message' => 'Match configuration created.',
            'data' => new ProcurementMatchConfigResource($config),
        ], Response::HTTP_CREATED);
    }

    public function updateConfig(Request $request, ProcurementMatchConfig $procurementMatchConfig): JsonResponse
    {
        $config = $this->procurementMatchConfigService->update(
            $procurementMatchConfig,
            $request->user('api'),
            $request->all(),
        );

        return response()->json([
            'message' => 'Match configuration updated.',
            'data' => new ProcurementMatchConfigResource($config),
        ]);
    }
}
