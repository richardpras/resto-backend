<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\PaymentHealthService;
use App\Modules\Payments\Services\PaymentHealthSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PaymentHealthController extends Controller
{
    public function __construct(
        private readonly PaymentHealthService $paymentHealthService,
        private readonly PaymentHealthSnapshotService $snapshotService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $provider = $request->query('provider');
        $outletId = $request->query('outletId') !== null ? (int) $request->query('outletId') : null;
        $providerKey = is_string($provider) && trim($provider) !== '' ? trim($provider) : null;

        $report = $this->paymentHealthService->report($providerKey, $outletId);
        $report['providerRanking'] = $this->paymentHealthService->providerRanking($outletId);

        return response()->json(['data' => $report]);
    }

    public function trends(Request $request): JsonResponse
    {
        $startDate = (string) $request->query('startDate', now()->subDays(30)->toDateString());
        $endDate = (string) $request->query('endDate', now()->toDateString());
        $outletId = $request->query('outletId') !== null ? (int) $request->query('outletId') : null;
        $provider = $request->query('provider');
        $providerKey = is_string($provider) && trim($provider) !== '' ? trim($provider) : null;

        if ($startDate > $endDate) {
            throw ValidationException::withMessages([
                'startDate' => ['startDate must be on or before endDate.'],
            ]);
        }

        return response()->json([
            'data' => $this->snapshotService->trends($outletId, $providerKey, $startDate, $endDate),
        ]);
    }

    public function reliability(Request $request): JsonResponse
    {
        $outletId = $request->query('outletId') !== null ? (int) $request->query('outletId') : null;

        return response()->json([
            'data' => $this->snapshotService->reliabilityReport($outletId),
        ]);
    }
}
