<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\DTOs\CreateStockMovementData;
use App\Modules\Inventory\Http\Requests\StoreStockMovementRequest;
use App\Modules\Inventory\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class StockMovementController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    public function index(): JsonResponse
    {
        /** Align with `IngredientController`: camelCase query keys; snake_case kept for backwards compatibility. */
        $tenantId = (int) request()->query('tenantId', request()->query('tenant_id', 0));
        abort_if($tenantId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'tenant_id is required');

        $rawOutlet = request()->query('outletId', request()->query('outlet_id'));
        $outletFilter = is_numeric($rawOutlet) ? (int) $rawOutlet : 0;

        $perPage = (int) request()->query('perPage', request()->query('per_page', 20));

        $movements = $this->inventoryService->listStockMovements(
            $tenantId,
            $perPage,
            $outletFilter > 0 ? $outletFilter : null,
            request()->user('api')
        );

        return response()->json([
            'data' => StockMovementResource::collection($movements->getCollection()),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'perPage' => $movements->perPage(),
                'total' => $movements->total(),
                'lastPage' => $movements->lastPage(),
            ],
        ]);
    }

    public function store(StoreStockMovementRequest $request): JsonResponse
    {
        $movement = $this->inventoryService->addStockMovement(
            CreateStockMovementData::fromArray($request->validated()),
            $request->user('api')
        );

        return response()->json([
            'message' => 'Stock movement created successfully.',
            'data' => new StockMovementResource($movement->load('ingredient')),
        ], Response::HTTP_CREATED);
    }
}
