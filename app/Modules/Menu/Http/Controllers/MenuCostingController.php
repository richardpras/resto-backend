<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MenuCostingController extends Controller
{
    public function __construct(
        private readonly RecipeCostService $recipeCostService,
    ) {}

    public function breakdown(int $menuItem): JsonResponse
    {
        $outletId = $this->requireOutletId();

        return response()->json([
            'data' => $this->recipeCostService->calculateMenuCostBreakdown(
                $menuItem,
                $outletId,
                request()->user('api'),
                logCalculated: true,
            ),
        ]);
    }

    public function history(int $menuItem): JsonResponse
    {
        $outletId = $this->requireOutletId();

        return response()->json([
            'data' => $this->recipeCostService->calculateHistoricalCost(
                $menuItem,
                $outletId,
                request()->query('fromDate'),
                request()->query('toDate'),
                request()->user('api'),
            ),
        ]);
    }

    public function foodCost(int $menuItem): JsonResponse
    {
        $outletId = $this->requireOutletId();

        return response()->json([
            'data' => $this->recipeCostService->calculateTheoreticalFoodCost(
                $menuItem,
                $outletId,
                request()->user('api'),
            ),
        ]);
    }

    public function recalculate(int $menuItem): JsonResponse
    {
        $outletId = $this->requireOutletId();

        return response()->json([
            'message' => 'Menu cost recalculated using latest outlet average costs.',
            'data' => $this->recipeCostService->recalculateMenuCost(
                $menuItem,
                $outletId,
                request()->user('api'),
            ),
        ]);
    }

    private function requireOutletId(): int
    {
        $raw = request()->query('outletId');
        abort_unless(is_numeric($raw) && (int) $raw >= 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        return (int) $raw;
    }
}
