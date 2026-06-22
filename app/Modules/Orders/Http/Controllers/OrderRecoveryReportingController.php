<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Services\OrderRecoveryReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderRecoveryReportingController extends Controller
{
    public function __construct(
        private readonly OrderRecoveryReportingService $reportingService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $outletId = $request->query('outletId');
        $parsedOutletId = is_numeric($outletId) ? (int) $outletId : null;

        return response()->json([
            'data' => $this->reportingService->summary($user, $parsedOutletId),
        ]);
    }

    private function resolveAuthenticatedUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
