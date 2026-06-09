<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\RecalculateInventoryValuationRequest;
use App\Modules\Inventory\Http\Resources\InventoryValuationResource;
use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InventoryValuationController extends Controller
{
    public function __construct(
        private readonly InventoryValuationService $inventoryValuationService,
    ) {}

    public function index(): JsonResponse
    {
        $rawOutletId = request()->query('outletId');
        $rawIngredientId = request()->query('ingredientId');
        $outletId = is_numeric($rawOutletId) && (int) $rawOutletId >= 1 ? (int) $rawOutletId : null;
        $ingredientId = is_numeric($rawIngredientId) && (int) $rawIngredientId >= 1 ? (int) $rawIngredientId : null;

        $rows = $this->inventoryValuationService->list($outletId, $ingredientId);

        return response()->json([
            'data' => InventoryValuationResource::collection($rows),
        ]);
    }

    public function show(int $ingredientId): JsonResponse
    {
        $rawOutletId = request()->query('outletId');
        abort_unless(is_numeric($rawOutletId) && (int) $rawOutletId >= 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        $outletId = (int) $rawOutletId;
        $snapshot = $this->inventoryValuationService->getInventoryValue($ingredientId, $outletId);
        $row = $this->inventoryValuationService->list($outletId, $ingredientId)->first();

        return response()->json([
            'data' => $row !== null
                ? new InventoryValuationResource($row)
                : array_merge(['ingredientId' => (string) $ingredientId, 'outletId' => $outletId], $snapshot),
        ]);
    }

    public function recalculate(RecalculateInventoryValuationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $rebuilt = $this->inventoryValuationService->recalculate(
            isset($validated['ingredientId']) ? (int) $validated['ingredientId'] : null,
            isset($validated['outletId']) ? (int) $validated['outletId'] : null,
            $request->user('api'),
        );

        return response()->json([
            'message' => 'Inventory valuation rebuild completed.',
            'data' => ['rebuiltPairs' => $rebuilt],
        ]);
    }
}
