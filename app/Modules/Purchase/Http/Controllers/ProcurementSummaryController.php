<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Services\ProcurementSummaryService;
use App\Modules\Purchase\Services\PurchaseScopeService;
use Illuminate\Http\JsonResponse;

class ProcurementSummaryController extends Controller
{
    public function __construct(
        private readonly ProcurementSummaryService $procurementSummaryService,
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    public function summary(): JsonResponse
    {
        $data = $this->procurementSummaryService->summary(
            request()->user('api'),
            $this->purchaseScopeService->requestedOutletIdFromRequest()
        );

        return response()->json(['data' => $data]);
    }
}
