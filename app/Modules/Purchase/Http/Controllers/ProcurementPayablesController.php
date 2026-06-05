<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Services\AccountsPayableSummaryService;
use App\Modules\Purchase\Services\PurchaseScopeService;
use Illuminate\Http\JsonResponse;

class ProcurementPayablesController extends Controller
{
    public function __construct(
        private readonly AccountsPayableSummaryService $accountsPayableSummaryService,
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->accountsPayableSummaryService->supplierPayables(
            request()->user('api'),
            $this->purchaseScopeService->requestedOutletIdFromRequest()
        );

        return response()->json([
            'data' => $rows,
        ]);
    }
}
