<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Monitoring\Http\Requests\GetDashboardSummaryRequest;
use App\Modules\Monitoring\Http\Resources\DashboardSummaryResource;
use App\Modules\Monitoring\Services\DashboardSummaryService;
use Illuminate\Http\JsonResponse;

class DashboardSummaryController extends Controller
{
    public function __construct(
        private readonly DashboardSummaryService $dashboardSummaryService,
    ) {}

    public function index(GetDashboardSummaryRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, 401, 'Unauthenticated.');

        $summary = $this->dashboardSummaryService->aggregate($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Dashboard summary retrieved successfully.',
            'data' => (new DashboardSummaryResource($summary))->resolve(),
            'meta' => null,
        ]);
    }
}

