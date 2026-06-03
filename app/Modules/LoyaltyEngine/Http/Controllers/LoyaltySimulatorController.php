<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LoyaltyEngine\Http\Requests\SimulateLoyaltyProgramRequest;
use App\Modules\LoyaltyEngine\Services\LoyaltyEngineAnalyticsService;
use App\Modules\LoyaltyEngine\Services\LoyaltySimulatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltySimulatorController extends Controller
{
    public function __construct(
        private readonly LoyaltySimulatorService $simulatorService,
        private readonly LoyaltyEngineAnalyticsService $analyticsService,
    ) {}

    public function simulate(SimulateLoyaltyProgramRequest $request): JsonResponse
    {
        $result = $this->simulatorService->simulate(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json(['data' => $result]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
        ]);

        $summary = $this->analyticsService->summary(
            $this->resolveUser($request),
            (int) $request->query('outletId'),
        );

        return response()->json(['data' => $summary]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
