<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Services\MenuEngineeringMatrixService;
use App\Modules\Menu\Services\MenuEngineeringSnapshotService;
use App\Modules\Menu\Services\MenuEngineeringTrendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MenuEngineeringController extends Controller
{
    public function __construct(
        private readonly MenuEngineeringMatrixService $matrixService,
        private readonly MenuEngineeringSnapshotService $snapshotService,
        private readonly MenuEngineeringTrendService $trendService,
    ) {}

    public function matrix(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->matrixService->generateMatrix(
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
                $request->user('api'),
            ),
        ]);
    }

    public function stars(Request $request): JsonResponse
    {
        return $this->classificationResponse($request, MenuEngineeringMatrixService::STAR);
    }

    public function puzzles(Request $request): JsonResponse
    {
        return $this->classificationResponse($request, MenuEngineeringMatrixService::PUZZLE);
    }

    public function plowhorses(Request $request): JsonResponse
    {
        return $this->classificationResponse($request, MenuEngineeringMatrixService::PLOWHORSE);
    }

    public function dogs(Request $request): JsonResponse
    {
        return $this->classificationResponse($request, MenuEngineeringMatrixService::DOG);
    }

    public function trends(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $fromDate = (string) $request->query('fromDate', now()->subMonth()->toDateString());
        $toDate = (string) $request->query('toDate', now()->toDateString());

        return response()->json([
            'data' => $this->trendService->calculateTrend($outletId, $fromDate, $toDate, $request->user('api')),
        ]);
    }

    public function menuItem(Request $request, int $menuItem): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $matrix = $this->matrixService->generateMatrix(
            $outletId,
            $request->query('fromDate'),
            $request->query('toDate'),
            $request->user('api'),
        );

        $item = collect($matrix['items'])->firstWhere('menuItemId', (string) $menuItem);
        abort_if($item === null, Response::HTTP_NOT_FOUND, 'Menu item not found in matrix.');

        return response()->json(['data' => $item]);
    }

    public function menuItemHistory(Request $request, int $menuItem): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->trendService->getMenuItemHistory(
                $menuItem,
                $outletId,
                $request->query('fromDate'),
                $request->query('toDate'),
            ),
        ]);
    }

    public function topPerformers(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $matrix = $this->matrixService->generateMatrix(
            $outletId,
            $request->query('fromDate'),
            $request->query('toDate'),
            $request->user('api'),
        );

        return response()->json([
            'data' => [
                'topStars' => $matrix['analytics']['topStars'],
                'highestMargin' => $matrix['analytics']['highestMargin'],
                'highestPopularity' => $matrix['analytics']['highestPopularity'],
            ],
        ]);
    }

    public function worstPerformers(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $matrix = $this->matrixService->generateMatrix(
            $outletId,
            $request->query('fromDate'),
            $request->query('toDate'),
            $request->user('api'),
        );

        return response()->json([
            'data' => [
                'topDogs' => $matrix['analytics']['topDogs'],
                'lowestMargin' => $matrix['analytics']['lowestMargin'],
                'lowestPopularity' => $matrix['analytics']['lowestPopularity'],
            ],
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
            'message' => 'Menu engineering snapshot created.',
            'data' => $snapshots->values(),
        ], Response::HTTP_CREATED);
    }

    private function classificationResponse(Request $request, string $classification): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $matrix = $this->matrixService->generateMatrix(
            $outletId,
            $request->query('fromDate'),
            $request->query('toDate'),
            $request->user('api'),
        );

        return response()->json([
            'data' => $this->matrixService->filterByClassification($matrix['items'], $classification),
        ]);
    }

    private function requireOutletId(Request $request): int
    {
        $raw = $request->query('outletId');
        abort_unless(is_numeric($raw) && (int) $raw >= 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        return (int) $raw;
    }
}
