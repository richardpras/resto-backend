<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\AccountingHealthService;
use App\Modules\Accounting\Services\AccountingHealthSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountingHealthController extends Controller
{
    public function __construct(
        private readonly AccountingHealthService $accountingHealthService,
        private readonly AccountingHealthSnapshotService $snapshotService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $outletId = $request->query('outletId') !== null ? (int) $request->query('outletId') : null;

        return response()->json([
            'data' => $this->accountingHealthService->report($request->user('api'), $outletId),
        ]);
    }

    public function trends(Request $request): JsonResponse
    {
        $startDate = (string) $request->query('startDate', now()->subDays(30)->toDateString());
        $endDate = (string) $request->query('endDate', now()->toDateString());
        $outletId = $request->query('outletId') !== null ? (int) $request->query('outletId') : null;

        if ($startDate > $endDate) {
            throw ValidationException::withMessages([
                'startDate' => ['startDate must be on or before endDate.'],
            ]);
        }

        return response()->json([
            'data' => $this->snapshotService->trends($outletId, $startDate, $endDate),
        ]);
    }
}
