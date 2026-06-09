<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\InventoryAnalyticsService;
use App\Modules\Menu\Services\AnalyticsSnapshotService;
use App\Modules\Menu\Services\ExecutiveAnalyticsService;
use App\Modules\Menu\Services\FoodCostAnalyticsService;
use App\Modules\Menu\Services\ProductionAnalyticsService;
use App\Modules\Menu\Services\ProfitabilityAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MenuAnalyticsController extends Controller
{
    public function __construct(
        private readonly ExecutiveAnalyticsService $executiveAnalytics,
        private readonly FoodCostAnalyticsService $foodCostAnalytics,
        private readonly ProfitabilityAnalyticsService $profitabilityAnalytics,
        private readonly ProductionAnalyticsService $productionAnalytics,
        private readonly InventoryAnalyticsService $inventoryAnalytics,
        private readonly AnalyticsSnapshotService $snapshotService,
    ) {}

    public function executive(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->executiveAnalytics->getExecutiveSummary(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function foodCost(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->foodCostAnalytics->getAverageFoodCost(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function foodCostTrend(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->foodCostAnalytics->getFoodCostTrend(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function foodCostHighest(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->foodCostAnalytics->getHighestFoodCostMenus(
                $outletId,
                (int) $request->query('limit', 10),
                $request->user('api'),
            ),
        ]);
    }

    public function foodCostLowest(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->foodCostAnalytics->getLowestFoodCostMenus(
                $outletId,
                (int) $request->query('limit', 10),
                $request->user('api'),
            ),
        ]);
    }

    public function foodCostIncreaseAlerts(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->foodCostAnalytics->detectFoodCostIncrease(
                $outletId,
                (float) $request->query('threshold', 5),
                $request->user('api'),
            ),
        ]);
    }

    public function profitability(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->profitabilityAnalytics->getSummary($outletId, $request->user('api')),
        ]);
    }

    public function profitabilityTrend(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->profitabilityAnalytics->getMarginTrend(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function profitabilityTopMargin(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->profitabilityAnalytics->getTopMarginMenus(
                $outletId,
                (int) $request->query('limit', 10),
                $request->user('api'),
            ),
        ]);
    }

    public function profitabilityLowMargin(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->profitabilityAnalytics->getLowestMarginMenus(
                $outletId,
                (int) $request->query('limit', 10),
                $request->user('api'),
            ),
        ]);
    }

    public function profitabilityErosionAlerts(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->profitabilityAnalytics->detectMarginErosion(
                $outletId,
                (float) $request->query('threshold', 5),
                $request->user('api'),
            ),
        ]);
    }

    public function production(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->productionAnalytics->getSummary(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function productionMostProduced(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->productionAnalytics->getMostProducedMenus(
                $outletId,
                (int) $request->query('limit', 10),
                $request->query('fromDate'),
                $request->query('toDate'),
            ),
        ]);
    }

    public function productionLeastProduced(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->productionAnalytics->getLeastProducedMenus(
                $outletId,
                (int) $request->query('limit', 10),
                $request->query('fromDate'),
                $request->query('toDate'),
            ),
        ]);
    }

    public function productionYieldLoss(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->productionAnalytics->getYieldLossAnalysis($outletId, $request->user('api')),
        ]);
    }

    public function productionEfficiency(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->productionAnalytics->getProductionEfficiency(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->inventoryAnalytics->getSummary($outletId, $request->user('api')),
        ]);
    }

    public function inventoryFastMoving(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->inventoryAnalytics->getFastMovingIngredients(
                $outletId,
                (int) $request->query('limit', 10),
                (int) $request->query('days', 30),
            ),
        ]);
    }

    public function inventorySlowMoving(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->inventoryAnalytics->getSlowMovingIngredients(
                $outletId,
                (int) $request->query('limit', 10),
                (int) $request->query('days', 30),
            ),
        ]);
    }

    public function inventoryDeadStock(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->inventoryAnalytics->getDeadStockIngredients(
                $outletId,
                (int) $request->query('days', 90),
                $request->user('api'),
            ),
        ]);
    }

    public function inventoryTurnover(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->inventoryAnalytics->getInventoryTurnover(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function inventoryValueTrend(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->inventoryAnalytics->getInventoryValueTrend(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function createSnapshot(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        $snapshot = $this->snapshotService->createDailySnapshot(
            $outletId,
            $request->input('snapshotDate'),
            $request->user('api'),
        );

        return response()->json([
            'message' => 'Analytics snapshot created.',
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
