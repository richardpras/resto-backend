<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Services\DemandForecastService;
use App\Modules\Menu\Services\FoodCostForecastService;
use App\Modules\Menu\Services\ForecastSnapshotService;
use App\Modules\Menu\Services\IngredientForecastService;
use App\Modules\Menu\Services\MenuForecastingService;
use App\Modules\Menu\Services\ProductionForecastService;
use App\Modules\Menu\Services\RevenueForecastService;
use App\Modules\Menu\Services\StockRiskForecastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MenuForecastingController extends Controller
{
    public function __construct(
        private readonly MenuForecastingService $forecastingService,
        private readonly DemandForecastService $demandForecast,
        private readonly RevenueForecastService $revenueForecast,
        private readonly FoodCostForecastService $foodCostForecast,
        private readonly IngredientForecastService $ingredientForecast,
        private readonly ProductionForecastService $productionForecast,
        private readonly StockRiskForecastService $stockRiskForecast,
        private readonly ForecastSnapshotService $snapshotService,
    ) {}

    public function demand(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->demandForecast->forecastOutlet(
                $outletId,
                $request->query('forecastDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->revenueForecast->forecastOutlet(
                $outletId,
                $request->query('forecastDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function foodCost(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->foodCostForecast->forecastOutlet(
                $outletId,
                $request->query('forecastDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function ingredients(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->ingredientForecast->forecastOutlet(
                $outletId,
                $request->query('forecastDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function production(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->productionForecast->forecastOutlet(
                $outletId,
                $request->query('forecastDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function stockRisk(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->stockRiskForecast->forecastOutlet(
                $outletId,
                $request->query('forecastDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function menuItem(Request $request, int $menuItem): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->forecastingService->getMenuItemForecast(
                $menuItem,
                $outletId,
                $request->query('forecastDate'),
            ),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->forecastingService->getSummary(
                $outletId,
                $request->query('forecastDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function snapshots(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->snapshotService->getSnapshots(
                $outletId,
                $request->query('snapshotDate'),
                $request->query('forecastDate'),
            ),
        ]);
    }

    public function createSnapshot(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $snapshots = $this->snapshotService->createSnapshot(
            $outletId,
            $request->input('snapshotDate'),
            $request->input('forecastDate'),
            $request->user('api'),
        );

        return response()->json([
            'message' => 'Forecast snapshot created.',
            'data' => $snapshots->values(),
        ], Response::HTTP_CREATED);
    }

    private function requireOutletId(Request $request): int
    {
        $raw = $request->query('outletId');
        abort_unless(is_numeric($raw) && (int) $raw >= 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        return (int) $raw;
    }
}
