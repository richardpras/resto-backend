<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\AccountingHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingHealthController extends Controller
{
    public function __construct(
        private readonly AccountingHealthService $accountingHealthService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $outletId = $request->query('outletId') !== null ? (int) $request->query('outletId') : null;

        return response()->json([
            'data' => $this->accountingHealthService->report($request->user('api'), $outletId),
        ]);
    }
}
