<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Services\DashboardService;
use App\Modules\Menu\Services\DashboardSnapshotService;
use App\Modules\Menu\Services\MenuHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MenuDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardSnapshotService $snapshotService,
        private readonly MenuHealthService $healthService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $this->dashboardService->recordView($outletId, $request->user('api'));

        return response()->json([
            'data' => $this->dashboardService->getSummary($outletId, $request->user('api')),
        ]);
    }

    public function kpis(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->dashboardService->getKpis($outletId, $request->user('api')),
        ]);
    }

    public function engineering(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->dashboardService->getEngineering($outletId, $request->user('api')),
        ]);
    }

    public function optimization(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->dashboardService->getOptimization($outletId, $request->user('api')),
        ]);
    }

    public function automation(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->dashboardService->getAutomation($outletId),
        ]);
    }

    public function forecasting(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->dashboardService->getForecasting($outletId, $request->user('api')),
        ]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->dashboardService->getInventory($outletId, $request->user('api')),
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->dashboardService->getHealth($outletId, $request->user('api')),
        ]);
    }

    public function systemHealth(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->healthService->getSystemHealth($outletId, $request->user('api')),
        ]);
    }

    public function snapshots(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->snapshotService->getSnapshots($outletId, $request->query('snapshotDate')),
        ]);
    }

    public function createSnapshot(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $snapshot = $this->snapshotService->createSnapshot(
            $outletId,
            $request->input('snapshotDate'),
            $request->user('api'),
        );

        return response()->json([
            'message' => 'Dashboard snapshot created.',
            'data' => $snapshot,
        ], Response::HTTP_CREATED);
    }

    private function requireOutletId(Request $request): int
    {
        $raw = $request->query('outletId');
        abort_unless(is_numeric($raw) && (int) $raw >= 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        return (int) $raw;
    }
}
