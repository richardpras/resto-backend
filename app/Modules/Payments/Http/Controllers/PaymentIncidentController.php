<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\PaymentIncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentIncidentController extends Controller
{
    public function __construct(
        private readonly PaymentIncidentService $paymentIncidentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $outletId = $request->query('outletId') !== null ? (int) $request->query('outletId') : null;
        $provider = $request->query('provider');
        $severity = $request->query('severity');
        $status = $request->query('status');
        $startDate = $request->query('startDate');
        $endDate = $request->query('endDate');

        return response()->json([
            'data' => $this->paymentIncidentService->listIncidents(
                $outletId,
                is_string($provider) ? $provider : null,
                is_string($severity) ? $severity : null,
                is_string($status) ? $status : null,
                is_string($startDate) ? $startDate : null,
                is_string($endDate) ? $endDate : null,
            ),
        ]);
    }
}
