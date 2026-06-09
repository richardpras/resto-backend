<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Services\HistoricalMarginService;
use App\Modules\Menu\Services\MenuPriceSimulationService;
use App\Modules\Menu\Services\MenuProfitabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MenuProfitabilityController extends Controller
{
    public function __construct(
        private readonly MenuProfitabilityService $profitabilityService,
        private readonly HistoricalMarginService $historicalMarginService,
        private readonly MenuPriceSimulationService $simulationService,
    ) {}

    public function show(int $menuItem): JsonResponse
    {
        $outletId = $this->requireOutletId();

        return response()->json([
            'data' => $this->profitabilityService->calculateProfitability(
                $menuItem,
                $outletId,
                request()->user('api'),
            ),
        ]);
    }

    public function history(int $menuItem): JsonResponse
    {
        $outletId = $this->requireOutletId();

        return response()->json([
            'data' => $this->historicalMarginService->compareHistoricalMargins(
                $menuItem,
                $outletId,
                request()->query('fromDate'),
                request()->query('toDate'),
                request()->user('api'),
            ),
        ]);
    }

    public function simulate(Request $request, int $menuItem): JsonResponse
    {
        $outletId = $this->requireOutletId();
        $validated = $request->validate([
            'proposedPrice' => ['nullable', 'numeric', 'min:0'],
            'proposedPrices' => ['nullable', 'array', 'min:1'],
            'proposedPrices.*' => ['numeric', 'min:0'],
        ]);

        $prices = [];
        if (isset($validated['proposedPrices']) && is_array($validated['proposedPrices'])) {
            $prices = array_values($validated['proposedPrices']);
        } elseif (isset($validated['proposedPrice'])) {
            $prices = [(float) $validated['proposedPrice']];
        }

        abort_if(
            $prices === [],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'proposedPrice or proposedPrices is required.',
        );

        return response()->json([
            'data' => $this->simulationService->simulate(
                $menuItem,
                $outletId,
                $prices,
                $request->user('api'),
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
