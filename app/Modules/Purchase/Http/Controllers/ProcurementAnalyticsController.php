<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Services\ProcurementAnalyticsService;
use App\Modules\Purchase\Services\PurchaseAuditService;
use App\Modules\Purchase\Services\PurchaseScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcurementAnalyticsController extends Controller
{
    public function __construct(
        private readonly ProcurementAnalyticsService $procurementAnalyticsService,
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly PurchaseAuditService $purchaseAuditService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $actor = $request->user('api');
        $outletId = $this->purchaseScopeService->requestedOutletIdFromRequest();
        $this->purchaseAuditService->logProcurementAnalytics('summary_viewed', $actor, $outletId);

        return response()->json([
            'data' => $this->procurementAnalyticsService->summary($actor, $outletId),
        ]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $actor = $request->user('api');
        $outletId = $this->purchaseScopeService->requestedOutletIdFromRequest();
        $this->purchaseAuditService->logProcurementAnalytics('supplier_performance_viewed', $actor, $outletId);

        return response()->json([
            'data' => $this->procurementAnalyticsService->suppliers($actor, $outletId),
        ]);
    }

    public function spend(Request $request): JsonResponse
    {
        $actor = $request->user('api');
        $outletId = $this->purchaseScopeService->requestedOutletIdFromRequest();
        $this->purchaseAuditService->logProcurementAnalytics('spend_analysis_viewed', $actor, $outletId);

        return response()->json([
            'data' => $this->procurementAnalyticsService->spend($actor, $outletId, [
                'fromDate' => $request->query('fromDate'),
                'toDate' => $request->query('toDate'),
                'supplierId' => $request->query('supplierId'),
                'categoryId' => $request->query('categoryId'),
                'warehouseId' => $request->query('warehouseId'),
            ]),
        ]);
    }

    public function payables(Request $request): JsonResponse
    {
        $actor = $request->user('api');
        $outletId = $this->purchaseScopeService->requestedOutletIdFromRequest();

        return response()->json([
            'data' => $this->procurementAnalyticsService->payables($actor, $outletId),
        ]);
    }

    public function trends(Request $request): JsonResponse
    {
        $actor = $request->user('api');
        $outletId = $this->purchaseScopeService->requestedOutletIdFromRequest();

        return response()->json([
            'data' => $this->procurementAnalyticsService->trends($actor, $outletId),
        ]);
    }

    public function posting(Request $request): JsonResponse
    {
        $actor = $request->user('api');
        $outletId = $this->purchaseScopeService->requestedOutletIdFromRequest();
        $this->purchaseAuditService->logProcurementAnalytics('posting_analytics_viewed', $actor, $outletId);

        return response()->json([
            'data' => $this->procurementAnalyticsService->posting($actor, $outletId),
        ]);
    }
}
