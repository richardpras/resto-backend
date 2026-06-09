<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Services\BundleRecommendationService;
use App\Modules\Menu\Services\IngredientOptimizationService;
use App\Modules\Menu\Services\MenuEngineeringMatrixService;
use App\Modules\Menu\Services\MenuOptimizationService;
use App\Modules\Menu\Services\MenuOptimizationSnapshotService;
use App\Modules\Menu\Services\MenuSimulationService;
use App\Modules\Menu\Services\PriceOptimizationService;
use App\Modules\Menu\Services\YieldOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MenuOptimizationController extends Controller
{
    public function __construct(
        private readonly MenuOptimizationService $optimizationService,
        private readonly PriceOptimizationService $priceService,
        private readonly BundleRecommendationService $bundleService,
        private readonly IngredientOptimizationService $ingredientService,
        private readonly YieldOptimizationService $yieldService,
        private readonly MenuSimulationService $simulationService,
        private readonly MenuOptimizationSnapshotService $snapshotService,
    ) {}

    public function recommendations(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->optimizationService->generateRecommendations(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function stars(Request $request): JsonResponse
    {
        return $this->classificationRecommendations($request, MenuEngineeringMatrixService::STAR);
    }

    public function puzzles(Request $request): JsonResponse
    {
        return $this->classificationRecommendations($request, MenuEngineeringMatrixService::PUZZLE);
    }

    public function plowhorses(Request $request): JsonResponse
    {
        return $this->classificationRecommendations($request, MenuEngineeringMatrixService::PLOWHORSE);
    }

    public function dogs(Request $request): JsonResponse
    {
        return $this->classificationRecommendations($request, MenuEngineeringMatrixService::DOG);
    }

    public function pricing(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->priceService->analyzeOutlet(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function pricingOpportunities(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $result = $this->priceService->analyzeOutlet(
            $outletId,
            $request->query('fromDate'),
            $request->query('toDate'),
            $request->user('api'),
        );

        return response()->json(['data' => $result['opportunities']]);
    }

    public function bundles(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->bundleService->analyzeOutlet(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function topBundles(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->bundleService->getTopBundles(
                $outletId,
                (int) $request->query('limit', 10),
                $request->query('fromDate'),
                $request->query('toDate'),
            ),
        ]);
    }

    public function ingredientOpportunities(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $result = $this->ingredientService->analyzeOutlet($outletId, $request->user('api'));

        return response()->json(['data' => $result['opportunities']]);
    }

    public function yieldOpportunities(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $result = $this->yieldService->analyzeOutlet($outletId, $request->user('api'));

        return response()->json(['data' => $result['opportunities']]);
    }

    public function simulatePrice(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $validated = $request->validate([
            'menuItemId' => ['required', 'integer', 'min:1'],
            'newPrice' => ['required', 'numeric', 'min:0'],
        ]);

        return response()->json([
            'data' => $this->simulationService->simulatePrice(
                (int) $validated['menuItemId'],
                $outletId,
                (float) $validated['newPrice'],
                $request->user('api'),
            ),
        ]);
    }

    public function simulateRecipe(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $validated = $request->validate([
            'menuItemId' => ['required', 'integer', 'min:1'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.inventoryItemId' => ['required', 'integer', 'min:1'],
            'changes.*.quantity' => ['sometimes', 'numeric', 'min:0'],
            'changes.*.newUnitCost' => ['sometimes', 'numeric', 'min:0'],
        ]);

        return response()->json([
            'data' => $this->simulationService->simulateRecipe(
                (int) $validated['menuItemId'],
                $outletId,
                $validated['changes'],
                $request->user('api'),
            ),
        ]);
    }

    public function simulateYield(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $validated = $request->validate([
            'menuItemId' => ['required', 'integer', 'min:1'],
            'newYieldPercent' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->simulationService->simulateYield(
                (int) $validated['menuItemId'],
                $outletId,
                (float) $validated['newYieldPercent'],
                $request->user('api'),
            ),
        ]);
    }

    public function createSnapshot(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $snapshots = $this->snapshotService->createSnapshot(
            $outletId,
            $request->input('snapshotDate'),
            $request->user('api'),
        );

        return response()->json([
            'message' => 'Menu optimization snapshot created.',
            'data' => $snapshots->values(),
        ], Response::HTTP_CREATED);
    }

    public function snapshots(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->snapshotService->getSnapshots($outletId, $request->query('snapshotDate')),
        ]);
    }

    private function classificationRecommendations(Request $request, string $classification): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->optimizationService->recommendationsByClassification(
                $outletId,
                $classification,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    private function requireOutletId(Request $request): int
    {
        $raw = $request->query('outletId');
        abort_unless(is_numeric($raw) && (int) $raw >= 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        return (int) $raw;
    }
}
