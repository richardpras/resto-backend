<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Monitoring\Http\Requests\GetMonitoringMetricsRequest;
use App\Modules\Monitoring\Http\Resources\MonitoringMetricsResource;
use App\Modules\Monitoring\Services\MonitoringMetricsService;
use Illuminate\Http\JsonResponse;

class MonitoringMetricsController extends Controller
{
    public function __construct(
        private readonly MonitoringMetricsService $metricsService,
    ) {}

    public function index(GetMonitoringMetricsRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, 401, 'Unauthenticated.');

        $metrics = $this->metricsService->aggregate($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Monitoring metrics retrieved successfully.',
            'data' => (new MonitoringMetricsResource($metrics))->resolve(),
            'meta' => null,
        ]);
    }
}
