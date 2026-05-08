<?php

namespace App\Modules\Loyalty\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Loyalty\Services\CustomerAnalyticsService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CrmMetricsController extends Controller
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly CustomerAnalyticsService $customerAnalyticsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $validated = validator($request->all(), [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id'],
        ])->validate();

        $outletId = (int) $validated['outletId'];
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages(['outletId' => ['The selected outletId is invalid.']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'CRM metrics retrieved successfully.',
            'data' => $this->customerAnalyticsService->metricsForOutlets([$outletId]),
            'meta' => null,
        ]);
    }
}
